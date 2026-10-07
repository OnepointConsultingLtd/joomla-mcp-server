/*
 * MCP Server for Joomla
 * Copyright (C) 2026 Onepoint Consulting Ltd
 * Licensed under the GNU General Public License version 2 or later.
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');

const {
    encodeHeaderValue,
    buildMcpHeaders,
    parseSseMessages,
    paramHeadersFromTools,
    createBridge,
} = require('../../site/mcp-http-bridge.js');

const MODERN_META = {
    'io.modelcontextprotocol/protocolVersion': '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities': {},
};

test('plain visible ASCII header values pass through unchanged', () => {
    assert.equal(encodeHeaderValue('search_articles'), 'search_articles');
    assert.equal(encodeHeaderValue('joomla://article/5'), 'joomla://article/5');
});

test('values a header cannot carry are base64-sentinel encoded', () => {
    assert.equal(encodeHeaderValue('Hello, 世界'), '=?base64?SGVsbG8sIOS4lueVjA==?=');
    assert.equal(encodeHeaderValue(' padded '), '=?base64?IHBhZGRlZCA=?=');
    assert.equal(encodeHeaderValue('line1\nline2'), '=?base64?bGluZTEKbGluZTI=?=');
    // A literal that looks like the sentinel must be encoded too, or the
    // server would decode it into something else.
    assert.equal(encodeHeaderValue('=?base64?literal?='), '=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?=');
});

test('modern requests mirror version, method and name into headers', () => {
    assert.deepEqual(
        buildMcpHeaders({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_article_by_id', _meta: MODERN_META } }, null),
        { 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/call', 'Mcp-Name': 'get_article_by_id' }
    );
    assert.deepEqual(
        buildMcpHeaders({ jsonrpc: '2.0', id: 1, method: 'resources/read', params: { uri: 'joomla://article/5', _meta: MODERN_META } }, null),
        { 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'resources/read', 'Mcp-Name': 'joomla://article/5' }
    );
});

test('legacy requests carry the version initialize negotiated', () => {
    assert.deepEqual(
        buildMcpHeaders({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, '2025-11-25'),
        { 'MCP-Protocol-Version': '2025-11-25', 'Mcp-Method': 'tools/list' }
    );
    assert.deepEqual(
        buildMcpHeaders({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-11-25' } }, null),
        { 'Mcp-Method': 'initialize' }
    );
});

test('SSE bodies yield each data message in order and skip comments', () => {
    const body = ': keep-alive\n\n'
        + 'event: message\ndata: {"jsonrpc":"2.0","method":"notifications/subscriptions/acknowledged","params":{}}\n\n'
        + 'event: message\r\ndata: {"jsonrpc":"2.0","id":1,"result":{}}\r\n\r\n';

    assert.deepEqual(parseSseMessages(body), [
        { jsonrpc: '2.0', method: 'notifications/subscriptions/acknowledged', params: {} },
        { jsonrpc: '2.0', id: 1, result: {} },
    ]);
});

/**
 * Run the bridge against a local HTTP server whose handler decides the reply.
 */
async function withBridge(handler, run) {
    const received = [];
    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => {
            const entry = { headers: req.headers, body: body ? JSON.parse(body) : null, req };
            received.push(entry);
            handler(entry, res);
        });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    const output = [];
    const bridge = createBridge({
        endpoint: `http://127.0.0.1:${server.address().port}/mcp`,
        bearerToken: 'secret',
        write: (message) => output.push(message),
        log: () => {},
    });

    try {
        await run(bridge, output, received);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
    }
}

function json(res, status, payload) {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(payload));
}

test('a JSON response is written and the request carries the MCP headers', async () => {
    await withBridge(
        ({ body }, res) => json(res, 200, { jsonrpc: '2.0', id: body.id, result: { resultType: 'complete', content: [] } }),
        async (bridge, output, received) => {
            await bridge.handleInput(JSON.stringify({
                jsonrpc: '2.0', id: 7, method: 'tools/call', params: { name: 'get_article_by_id', arguments: { id: 1 }, _meta: MODERN_META },
            }));

            assert.deepEqual(output, [{ jsonrpc: '2.0', id: 7, result: { resultType: 'complete', content: [] } }]);
            const { headers } = received[0];
            assert.equal(headers.accept, 'application/json, text/event-stream');
            assert.equal(headers.authorization, 'Bearer secret');
            assert.equal(headers['mcp-protocol-version'], '2026-07-28');
            assert.equal(headers['mcp-method'], 'tools/call');
            assert.equal(headers['mcp-name'], 'get_article_by_id');
        }
    );
});

for (const status of [202, 204]) {
    test(`an empty ${status} reply to a notification produces no output`, async () => {
        await withBridge(
            (_entry, res) => { res.writeHead(status); res.end(); },
            async (bridge, output) => {
                await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }));

                assert.deepEqual(output, []);
            }
        );
    });
}

test('an SSE response is relayed message by message', async () => {
    await withBridge(
        ({ body }, res) => {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            res.write('event: message\ndata: ' + JSON.stringify({
                jsonrpc: '2.0',
                method: 'notifications/subscriptions/acknowledged',
                params: { _meta: { 'io.modelcontextprotocol/subscriptionId': body.id }, notifications: {} },
            }) + '\n\n');
            res.end('event: message\ndata: ' + JSON.stringify({ jsonrpc: '2.0', id: body.id, result: { resultType: 'complete' } }) + '\n\n');
        },
        async (bridge, output) => {
            await bridge.handleInput(JSON.stringify({
                jsonrpc: '2.0', id: 3, method: 'subscriptions/listen', params: { notifications: {}, _meta: MODERN_META },
            }));

            assert.deepEqual(output.map((message) => message.method ?? 'response'), ['notifications/subscriptions/acknowledged', 'response']);
            assert.equal(output[1].id, 3);
        }
    );
});

test('the version negotiated by initialize is sent on later legacy requests', async () => {
    await withBridge(
        ({ body }, res) => json(res, 200, body.method === 'initialize'
            ? { jsonrpc: '2.0', id: body.id, result: { protocolVersion: '2025-06-18', capabilities: {}, serverInfo: { name: 'joomla', version: '1' } } }
            : { jsonrpc: '2.0', id: body.id, result: { tools: [] } }),
        async (bridge, _output, received) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18' } }));
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }));

            assert.equal(received[0].headers['mcp-protocol-version'], undefined);
            assert.equal(received[1].headers['mcp-protocol-version'], '2025-06-18');
        }
    );
});

test('a cancelled request is aborted and produces no output', async () => {
    await withBridge(
        () => { /* never reply: the request stays in flight until cancelled */ },
        async (bridge, output, received) => {
            const pending = bridge.handleInput(JSON.stringify({
                jsonrpc: '2.0', id: 11, method: 'tools/call', params: { name: 'slow_tool', arguments: {}, _meta: MODERN_META },
            }));
            while (received.length === 0) {
                await new Promise((resolve) => setImmediate(resolve));
            }
            const closed = new Promise((resolve) => received[0].req.socket.once('close', resolve));

            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/cancelled', params: { requestId: 11 } }));
            await pending;
            await closed;

            assert.deepEqual(output, [], 'no further message may be sent for a cancelled request');
            assert.equal(received.length, 1, 'the cancellation itself is not forwarded');
        }
    );
});

test('an HTTP error without a JSON body becomes a JSON-RPC error for the request', async () => {
    await withBridge(
        (_entry, res) => { res.writeHead(502, { 'Content-Type': 'text/html' }); res.end('<h1>Bad Gateway</h1>'); },
        async (bridge, output) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 5, method: 'tools/list', params: { _meta: MODERN_META } }));

            assert.equal(output.length, 1);
            assert.equal(output[0].id, 5);
            assert.equal(output[0].error.code, -32603);
            assert.match(output[0].error.message, /HTTP Error 502/);
        }
    );
});

test('a request whose reply carries no response gets an error instead of hanging', async () => {
    await withBridge(
        ({ body }, res) => {
            if (body.id === 'stream') {
                res.writeHead(200, { 'Content-Type': 'text/event-stream' });
                res.end('event: message\ndata: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":1,"progress":1}}\n\n');
                return;
            }
            res.writeHead(202);
            res.end();
        },
        async (bridge, output) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 'stream', method: 'tools/call', params: { name: 'x', _meta: MODERN_META } }));
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 'empty', method: 'tools/call', params: { name: 'x', _meta: MODERN_META } }));

            assert.deepEqual(output.map((message) => message.method ?? `${message.id}:${message.error?.code}`), [
                'notifications/progress',
                'stream:-32603',
                'empty:-32603',
            ]);
        }
    );
});

test('progress notifications are relayed while the stream is still open', async () => {
    let release;
    const released = new Promise((resolve) => { release = resolve; });

    await withBridge(
        async ({ body }, res) => {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            res.write('event: message\ndata: ' + JSON.stringify({
                jsonrpc: '2.0', method: 'notifications/progress', params: { progressToken: 't', progress: 1, total: 2 },
            }) + '\n\n');
            await released;
            res.end('event: message\ndata: ' + JSON.stringify({ jsonrpc: '2.0', id: body.id, result: { resultType: 'complete', content: [] } }) + '\n\n');
        },
        async (bridge, output) => {
            const pending = bridge.handleInput(JSON.stringify({
                jsonrpc: '2.0', id: 4, method: 'tools/call',
                params: { name: 'seo_audit_articles', arguments: {}, _meta: { ...MODERN_META, progressToken: 't' } },
            }));

            const deadline = Date.now() + 2000;
            while (output.length === 0 && Date.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 5));
            }
            assert.equal(output.length, 1, 'the progress notification must arrive before the stream ends');
            assert.equal(output[0].method, 'notifications/progress');

            release();
            await pending;
            assert.equal(output[1].id, 4);
        }
    );
});

const ANNOTATED_TOOLS = [
    { name: 'get_article_by_id', inputSchema: { type: 'object', properties: { id: { type: 'integer', 'x-mcp-header': 'Id' } } } },
    { name: 'search_articles', inputSchema: { type: 'object', properties: { search: { type: 'string' } } } },
];

test('annotations become a per-tool header map', () => {
    const map = paramHeadersFromTools(ANNOTATED_TOOLS);

    assert.deepEqual(map.get('get_article_by_id'), [['id', 'Id']]);
    assert.deepEqual(map.get('search_articles'), []);
});

test('tools/call mirrors annotated arguments into Mcp-Param headers', () => {
    const map = paramHeadersFromTools(ANNOTATED_TOOLS);
    const headers = (args) => buildMcpHeaders(
        { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_article_by_id', arguments: args, _meta: MODERN_META } },
        null,
        map
    );

    assert.equal(headers({ id: 5 })['Mcp-Param-Id'], '5');
    assert.equal(headers({})['Mcp-Param-Id'], undefined);
    assert.equal(headers({ id: null })['Mcp-Param-Id'], undefined);
});

test('param headers learned from a relayed tools/list are sent on later calls', async () => {
    await withBridge(
        ({ body }, res) => json(res, 200, body.method === 'tools/list'
            ? { jsonrpc: '2.0', id: body.id, result: { tools: ANNOTATED_TOOLS } }
            : { jsonrpc: '2.0', id: body.id, result: { content: [] } }),
        async (bridge, _output, received) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: { _meta: MODERN_META } }));
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'get_article_by_id', arguments: { id: 5 }, _meta: MODERN_META } }));

            assert.equal(received[1].headers['mcp-param-id'], '5');
        }
    );
});

test('an unknown schema is learned once on HeaderMismatch and the call retried', async () => {
    await withBridge(
        ({ body, headers }, res) => {
            if (body.method === 'tools/list') {
                json(res, 200, { jsonrpc: '2.0', id: body.id, result: { tools: ANNOTATED_TOOLS } });
            } else if (headers['mcp-param-id'] === undefined) {
                json(res, 400, { jsonrpc: '2.0', id: body.id, error: { code: -32020, message: 'Missing Mcp-Param-Id header' } });
            } else {
                json(res, 200, { jsonrpc: '2.0', id: body.id, result: { content: [], ok: true } });
            }
        },
        async (bridge, output, received) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'tools/call', params: { name: 'get_article_by_id', arguments: { id: 5 }, _meta: { ...MODERN_META, progressToken: 'p' } } }));

            assert.deepEqual(received.map((entry) => entry.body.method), ['tools/call', 'tools/list', 'tools/call']);
            assert.equal(received[1].body.params._meta['io.modelcontextprotocol/protocolVersion'], '2026-07-28');
            assert.equal(received[1].body.params._meta.progressToken, undefined, 'the refresh is not the call: it carries only protocol metadata');
            assert.equal(output.length, 1, 'only the retried call answers the client');
            assert.equal(output[0].id, 9, 'under the id the client used');
            assert.equal(output[0].result.ok, true);
        }
    );
});
