#!/usr/bin/env node

/*
 * MCP Server for Joomla
 * Copyright (C) 2026 Onepoint Consulting Ltd
 * Licensed under the GNU General Public License version 2 or later.
 */

/**
 * MCP HTTP-to-stdio Bridge
 *
 * This script bridges Claude Desktop's stdio transport to a remote HTTP MCP server.
 * Usage: node mcp-http-bridge.js <endpoint-url> [bearer-token]
 *
 * It relays messages unchanged and speaks every protocol era the server does:
 * legacy (initialize-based) clients and stateless 2026-07-28 clients alike.
 * Streamable HTTP requires request metadata mirrored into headers, which a stdio
 * client never sends, so the bridge derives them from each message body.
 */

const https = require('https');
const http = require('http');
const { URL } = require('url');

const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

// Methods whose params.name / params.uri is mirrored into the Mcp-Name header.
const NAME_SOURCES = {
    'tools/call': 'name',
    'prompts/get': 'name',
    'resources/read': 'uri',
};

const BASE64_PREFIX = '=?base64?';
const BASE64_SUFFIX = '?=';

const PAGINATED_LIST_METHODS = {
    'tools/list': 'tools',
    'resources/list': 'resources',
    'resources/templates/list': 'resourceTemplates',
    'prompts/list': 'prompts',
};

/**
 * Header values must be visible ASCII; anything else — and any literal that
 * looks like the sentinel itself — travels as `=?base64?…?=`.
 */
function encodeHeaderValue(value) {
    const text = String(value);
    const plain = /^[\x20-\x7E]*$/.test(text)
        && text === text.trim()
        && !(text.startsWith(BASE64_PREFIX) && text.endsWith(BASE64_SUFFIX));

    return plain ? text : `${BASE64_PREFIX}${Buffer.from(text, 'utf8').toString('base64')}${BASE64_SUFFIX}`;
}

/**
 * The request-metadata headers for one message: the protocol version it
 * declares (or, for a legacy client, the one initialize negotiated), its
 * method, and its name or URI where the method has one.
 */
function buildMcpHeaders(message, negotiatedVersion, paramHeaders = new Map()) {
    const headers = {};
    const params = message && typeof message.params === 'object' && message.params !== null ? message.params : {};
    const meta = typeof params._meta === 'object' && params._meta !== null ? params._meta : {};
    const version = typeof meta[META_PROTOCOL_VERSION] === 'string' ? meta[META_PROTOCOL_VERSION] : negotiatedVersion;

    if (version) {
        headers['MCP-Protocol-Version'] = version;
    }
    if (typeof message.method === 'string') {
        headers['Mcp-Method'] = message.method;
    }
    const nameField = NAME_SOURCES[message.method];
    if (nameField && typeof params[nameField] === 'string') {
        headers['Mcp-Name'] = encodeHeaderValue(params[nameField]);
    }

    if (message.method === 'tools/call' && typeof params.name === 'string') {
        const args = params.arguments && typeof params.arguments === 'object' ? params.arguments : {};
        for (const [property, header] of paramHeaders.get(params.name) || []) {
            const value = args[property];
            if (value !== undefined && value !== null) {
                headers[`Mcp-Param-${header}`] = encodeHeaderValue(String(value));
            }
        }
    }

    return headers;
}

/**
 * Only the per-request protocol keys of a _meta object: a request the bridge
 * makes on a call's behalf must not inherit the call's own progress token.
 */
function protocolMeta(meta) {
    const keys = ['io.modelcontextprotocol/protocolVersion', 'io.modelcontextprotocol/clientCapabilities', 'io.modelcontextprotocol/clientInfo'];
    const picked = {};
    for (const key of keys) {
        if (meta && key in meta) {
            picked[key] = meta[key];
        }
    }

    return picked;
}

/**
 * Tool name → [[property, header]] from a tools/list result's x-mcp-header
 * annotations, so tools/call can mirror those arguments into Mcp-Param-* headers.
 */
function paramHeadersFromTools(tools) {
    const map = new Map();
    for (const tool of Array.isArray(tools) ? tools : []) {
        if (!tool || typeof tool.name !== 'string') {
            continue;
        }
        const properties = tool.inputSchema && typeof tool.inputSchema.properties === 'object' ? tool.inputSchema.properties : {};
        const pairs = [];
        for (const [property, schema] of Object.entries(properties || {})) {
            if (schema && typeof schema['x-mcp-header'] === 'string') {
                pairs.push([property, schema['x-mcp-header']]);
            }
        }
        map.set(tool.name, pairs);
    }

    return map;
}

/**
 * The JSON-RPC messages of a Server-Sent Events body, in order. Comment lines
 * (keep-alives) and events without data are skipped.
 */
function parseSseMessages(body) {
    const messages = [];
    for (const event of body.split(/\r?\n\r?\n/)) {
        const data = event
            .split(/\r?\n/)
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).replace(/^ /, ''))
            .join('\n');
        if (data.trim() !== '') {
            messages.push(JSON.parse(data));
        }
    }

    return messages;
}

function createBridge({ endpoint, bearerToken = '', rejectUnauthorized = true, write, log = () => {} }) {
    let serverName = 'remote-mcp-server';
    // Legacy clients declare their version once, in initialize; later requests
    // must still carry it in MCP-Protocol-Version.
    let negotiatedVersion = null;
    // stdio request id → { cancelled, req }: on Streamable HTTP, closing the
    // request's connection is the cancellation signal.
    const inFlight = new Map();
    const toolParamHeaders = new Map();

    /**
     * POST one message and resolve with every JSON-RPC message the server
     * answered with: none for an accepted notification, several for an SSE
     * stream, otherwise one.
     */
    function post(message, flight, onNotification = null) {
        return new Promise((resolve, reject) => {
            const url = new URL(endpoint);
            const isHttps = url.protocol === 'https:';
            const client = isHttps ? https : http;
            const payload = JSON.stringify(message);

            const options = {
                hostname: url.hostname,
                port: url.port || (isHttps ? 443 : 80),
                path: url.pathname + url.search,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(payload),
                    'Accept': 'application/json, text/event-stream',
                    ...buildMcpHeaders(message, negotiatedVersion, toolParamHeaders),
                },
                rejectUnauthorized: rejectUnauthorized
            };

            if (bearerToken) {
                options.headers['Authorization'] = `Bearer ${bearerToken}`;
            }

            const req = client.request(options, (res) => {
                res.setEncoding('utf8');
                const streaming = String(res.headers['content-type'] || '').startsWith('text/event-stream');
                const messages = [];
                let data = '';
                let pending = '';
                let failure = null;

                // Each complete event is handled as it arrives: progress must reach
                // the client live, not when the stream ends.
                const deliver = (event) => {
                    for (const parsed of parseSseMessages(event)) {
                        messages.push(parsed);
                        if (onNotification && !('id' in parsed)) {
                            onNotification(parsed);
                        }
                    }
                };

                res.on('data', (chunk) => {
                    if (!streaming) {
                        data += chunk;
                        return;
                    }
                    pending += chunk;
                    const events = pending.split(/\r?\n\r?\n/);
                    pending = events.pop();
                    try {
                        events.forEach(deliver);
                    } catch (error) {
                        failure = failure || error;
                    }
                });

                res.on('end', () => {
                    if (streaming) {
                        try {
                            if (pending.trim() !== '') {
                                deliver(pending);
                            }
                        } catch (error) {
                            failure = failure || error;
                        }
                        if (failure) {
                            reject(new Error(`Invalid event stream (HTTP ${res.statusCode}): ${failure.message}`));
                            return;
                        }
                        // Notifications already went out; hand back what remains.
                        resolve(onNotification ? messages.filter((m) => 'id' in m) : messages);
                        return;
                    }
                    if ((res.statusCode === 202 || res.statusCode === 204) && data.trim() === '') {
                        resolve([]);
                        return;
                    }
                    if (res.statusCode >= 400 && !data.trim().startsWith('{')) {
                        reject(new Error(`HTTP Error ${res.statusCode}: ${data.slice(0, 100)}`));
                        return;
                    }
                    try {
                        const response = JSON.parse(data);
                        if (response && response.id === null && message.id !== undefined && message.id !== null) {
                            response.id = message.id;
                        }
                        resolve([response]);
                    } catch (error) {
                        reject(new Error(`Invalid JSON response (HTTP ${res.statusCode}): ${error.message}`));
                    }
                });
            });

            if (flight) {
                flight.req = req;
            }
            req.on('error', reject);
            req.write(payload);
            req.end();
        });
    }

    async function fetchAllListPages(message, itemsKey, flight) {
        const allItems = [];
        let cursor = message.params?.cursor;
        let lastResponse = null;
        let page = 0;

        while (true) {
            const pageParams = { ...(message.params || {}) };
            if (cursor) {
                pageParams.cursor = cursor;
            } else {
                delete pageParams.cursor;
            }

            const pageId = page === 0 ? message.id : `${message.id}-page-${page}`;
            const [response] = await post({ jsonrpc: '2.0', id: pageId, method: message.method, params: pageParams }, flight);

            if (!response || response.error) {
                return response ? { ...response, id: message.id } : null;
            }

            lastResponse = response;
            const items = response.result?.[itemsKey];
            if (Array.isArray(items)) {
                allItems.push(...items);
            }

            cursor = response.result?.nextCursor;
            if (!cursor) {
                break;
            }
            page++;
        }

        const result = { ...lastResponse.result, [itemsKey]: allItems };
        delete result.nextCursor;
        return { ...lastResponse, id: message.id, result };
    }

    function cancel(requestId) {
        const flight = inFlight.get(requestId);
        if (flight) {
            flight.cancelled = true;
            if (flight.req) {
                flight.req.destroy();
            }
        }
    }

    function remember(message, response) {
        const result = response && response.result;
        if (!result) {
            return;
        }
        const info = result.serverInfo || (result._meta && result._meta[META_SERVER_INFO]);
        if (info && info.name) {
            serverName = info.name;
        }
        if (Array.isArray(result.tools)) {
            for (const [name, pairs] of paramHeadersFromTools(result.tools)) {
                toolParamHeaders.set(name, pairs);
            }
        }
        if (message.method === 'initialize' && typeof result.protocolVersion === 'string') {
            negotiatedVersion = result.protocolVersion;
        }
    }

    async function handleInput(line) {
        let requestId = undefined;
        let flight = null;
        try {
            const request = JSON.parse(line);
            requestId = request.id !== undefined ? request.id : undefined;
            const { method, params } = request;

            log(`Handling ${method} request`, { server: serverName });

            // Handled here rather than forwarded: aborting the matching HTTP
            // request is what cancels it on the server.
            if (method === 'notifications/cancelled') {
                cancel(params?.requestId);
                return;
            }

            const message = { jsonrpc: '2.0', id: requestId, method, params };
            if (requestId !== undefined) {
                flight = { cancelled: false, req: null };
                inFlight.set(requestId, flight);
            }

            const itemsKey = PAGINATED_LIST_METHODS[method];
            // Notifications (progress, subscription acks) are written the moment
            // they arrive; responses are written below, after any retry decision.
            const relayNotification = (notification) => {
                if (!flight || !flight.cancelled) {
                    write(notification);
                }
            };
            let responses = itemsKey && !params?.cursor
                ? [await fetchAllListPages(message, itemsKey, flight)].filter(Boolean)
                : await post(message, flight, relayNotification);

            // A client may call a tool before this bridge has relayed its schema;
            // its Mcp-Param-* headers were then missing. Learn the schema and retry
            // once, as the transport spec advises on HeaderMismatch.
            const mismatch = responses.some((r) => r && r.error && r.error.code === -32020);
            if (method === 'tools/call' && mismatch && !toolParamHeaders.has(params?.name)) {
                const listed = await fetchAllListPages(
                    { jsonrpc: '2.0', id: `${requestId}-tools`, method: 'tools/list', params: { _meta: protocolMeta(params?._meta) } },
                    'tools',
                    flight
                );
                remember({ method: 'tools/list' }, listed);
                if (toolParamHeaders.has(params?.name)) {
                    responses = (await post({ ...message, id: `${requestId}-retry` }, flight, relayNotification))
                        .map((r) => ('id' in r ? { ...r, id: requestId } : r));
                }
            }

            if (flight && flight.cancelled) {
                return;
            }

            for (const response of responses) {
                remember(message, response);
                if (method === 'tools/list' && response.result && response.result.tools) {
                    log(`listTools: Found ${response.result.tools.length} tools`, { server: serverName });
                }
                write(response);
            }

            // A request the server answered without a response — an empty 202, or
            // a stream that ended early — would otherwise leave the client waiting
            // forever for it.
            const answered = responses.some((response) => 'result' in response || 'error' in response);
            if (requestId !== undefined && !answered) {
                throw new Error(`No response from the server for ${method}`);
            }
        } catch (error) {
            // A cancelled request must stay silent: the client has already
            // given up on it, and the abort itself surfaces here as an error.
            if (flight && flight.cancelled) {
                return;
            }
            write({
                jsonrpc: '2.0',
                id: requestId,
                error: {
                    code: -32603,
                    message: error.message
                }
            });
        } finally {
            if (requestId !== undefined && inFlight.get(requestId) === flight) {
                inFlight.delete(requestId);
            }
        }
    }

    return { handleInput };
}

function main() {
    const endpoint = process.argv[2];
    const bearerToken = process.argv[3] || process.env.HTTP_AUTH_BEARER || '';
    const rejectUnauthorized = process.env.MCP_IGNORE_SSL !== '1';

    function log(message, data = null) {
        const timestamp = new Date().toISOString().replace('T', ' ').replace('Z', '');
        const dataStr = data ? ` ${JSON.stringify(data)}` : '';
        process.stderr.write(`${timestamp} [info] ${message}${dataStr}\n`);
    }

    if (!endpoint) {
        console.error('Error: Endpoint URL is required');
        console.error('Usage: node mcp-http-bridge.js <endpoint-url> [bearer-token]');
        process.exit(1);
    }

    log(`Connected to stdio server, bridging to ${endpoint}`);

    const bridge = createBridge({
        endpoint,
        bearerToken,
        rejectUnauthorized,
        write: (data) => process.stdout.write(JSON.stringify(data) + '\n'),
        log,
    });

    let buffer = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => {
        buffer += chunk;
        const lines = buffer.split('\n');
        buffer = lines.pop();

        for (const line of lines) {
            if (line.trim()) {
                bridge.handleInput(line.trim());
            }
        }
    });

    process.stdin.on('end', () => {
        if (buffer.trim()) {
            bridge.handleInput(buffer.trim());
        }
    });

    process.on('SIGINT', () => process.exit(0));
    process.on('SIGTERM', () => process.exit(0));
}

module.exports = { encodeHeaderValue, buildMcpHeaders, parseSseMessages, paramHeadersFromTools, createBridge };

if (require.main === module) {
    main();
}
