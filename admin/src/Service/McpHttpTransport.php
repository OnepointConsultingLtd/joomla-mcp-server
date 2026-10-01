<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Streamable HTTP transport rules, kept free of superglobals and header()
 * calls so RpcHandlerTrait's HTTP behaviour can be unit tested.
 *
 * Header names are compared case-insensitively (callers pass a lowercase map);
 * header values are case-sensitive.
 */
final class McpHttpTransport
{
    /** Methods whose params.name / params.uri is mirrored into Mcp-Name. */
    private const NAME_SOURCES = [
        'tools/call' => 'name',
        'prompts/get' => 'name',
        'resources/read' => 'uri',
    ];

    private const BASE64_PREFIX = '=?base64?';

    private const BASE64_SUFFIX = '?=';

    /**
     * The MCP request headers, keyed by lowercase header name.
     *
     * @param  array<string, mixed>  $server  typically $_SERVER
     * @return array<string, string>
     */
    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_MCP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }

    /**
     * Validate the request-metadata headers against the body.
     *
     * Precedence is deliberate: an unsupported version (header, then body) is
     * reported before any mismatch, because the client can recover from it by
     * retrying with a version from `supported`; a mismatch it can only fix by
     * repairing itself. Mcp-Param-* headers are not checked: they are only
     * defined for tool parameters annotated with x-mcp-header, and no tool here
     * declares one.
     *
     * @param  array<string, mixed>   $request
     * @param  array<string, string>  $headers  lowercase header name => value
     *
     * @throws McpProtocolError
     */
    public static function validate(array $request, array $headers): void
    {
        $method = is_string($request['method'] ?? null) ? $request['method'] : '';

        // Notifications carry no request metadata, so they are not checked —
        // which is why only notification methods may arrive without an id: a
        // tools/call sent that way would run past the headers a gateway routed on.
        if (!array_key_exists('id', $request)) {
            if ($method !== '' && !str_starts_with($method, 'notifications/')) {
                throw new McpProtocolError(
                    'Requests must carry an id; only notifications/* methods may be sent without one',
                    JsonRpc::INVALID_REQUEST
                );
            }

            return;
        }

        // initialize always selects legacy semantics, whatever the client prefers.
        if ($method === 'initialize') {
            return;
        }

        $headerVersion = $headers['mcp-protocol-version'] ?? null;
        if ($headerVersion !== null) {
            self::assertHeaderSafe('MCP-Protocol-Version', $headerVersion);
            if (!McpProtocol::isSupported($headerVersion)) {
                throw McpProtocol::unsupportedVersion($headerVersion);
            }
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $bodyHasVersion = array_key_exists(McpProtocol::META_PROTOCOL_VERSION, $meta);
        $bodyVersion = $meta[McpProtocol::META_PROTOCOL_VERSION] ?? null;

        if ($bodyHasVersion && !McpProtocol::isSupported($bodyVersion)) {
            throw McpProtocol::unsupportedVersion(is_string($bodyVersion) ? $bodyVersion : (string) json_encode($bodyVersion));
        }

        if (!McpProtocol::isModern($headerVersion) && !McpProtocol::isModern($bodyVersion)) {
            return;
        }

        if (!$bodyHasVersion) {
            throw new McpProtocolError(
                'Missing required _meta field ' . McpProtocol::META_PROTOCOL_VERSION,
                JsonRpc::INVALID_PARAMS
            );
        }

        if ($headerVersion === null) {
            // The discovery probe is the one exception. A dual-era client probes
            // with server/discover and falls back to initialize on any error that
            // is not a modern one — so answering it as the header-less 2025-03-26
            // request the spec lets us treat it as (where server/discover does
            // not exist) keeps such a client working through an old bridge.
            if ($method === 'server/discover') {
                throw new McpProtocolError(
                    'server/discover requires the MCP-Protocol-Version header. If you connect through the MCP Server '
                    . 'for Joomla bridge or Claude Desktop extension, download it again from the component',
                    JsonRpc::METHOD_NOT_FOUND,
                    [],
                    200
                );
            }

            // The likeliest sender is a bridge or Claude Desktop extension
            // downloaded before this server spoke 2026-07-28: say how to fix it.
            throw self::mismatch(
                'Missing MCP-Protocol-Version header. If you connect through the MCP Server for Joomla '
                . 'bridge or Claude Desktop extension, download it again from the component'
            );
        }

        if ($headerVersion !== $bodyVersion) {
            throw self::mismatch("Header mismatch: MCP-Protocol-Version header value '{$headerVersion}' does not match body value '{$bodyVersion}'");
        }

        $methodHeader = $headers['mcp-method'] ?? null;
        if ($methodHeader === null) {
            throw self::mismatch('Missing Mcp-Method header');
        }
        self::assertHeaderSafe('Mcp-Method', $methodHeader);
        if ($methodHeader !== $method) {
            throw self::mismatch("Header mismatch: Mcp-Method header value '{$methodHeader}' does not match body value '{$method}'");
        }

        $nameField = self::NAME_SOURCES[$method] ?? null;
        if ($nameField === null) {
            return;
        }

        $nameHeader = $headers['mcp-name'] ?? null;
        if ($nameHeader === null) {
            throw self::mismatch('Missing Mcp-Name header');
        }
        $name = self::decodeHeaderValue('Mcp-Name', $nameHeader);
        $bodyName = $params[$nameField] ?? null;
        if (!is_string($bodyName) || $name !== $bodyName) {
            throw self::mismatch("Header mismatch: Mcp-Name header does not match body value params.{$nameField}");
        }
    }

    /**
     * Only legacy clients may batch. 2026-07-28 forbids it, and a batch mixing
     * in a modern request cannot be validated against per-request headers, so
     * the whole body is rejected.
     *
     * @param  list<mixed>            $batch
     * @param  array<string, string>  $headers
     *
     * @throws McpProtocolError
     */
    public static function validateBatch(array $batch, array $headers): void
    {
        $headerVersion = $headers['mcp-protocol-version'] ?? null;
        if ($headerVersion !== null && !McpProtocol::isSupported($headerVersion)) {
            throw McpProtocol::unsupportedVersion($headerVersion);
        }

        $modern = McpProtocol::isModern($headerVersion);
        foreach ($batch as $entry) {
            $modern = $modern || (is_array($entry) && McpProtocol::isModernRequest($entry));
        }

        if ($modern) {
            throw new McpProtocolError('JSON-RPC batches are not supported in protocol version 2026-07-28', JsonRpc::INVALID_REQUEST);
        }
    }

    /**
     * Whether a request's Origin may be served. Browsers attach Origin to
     * cross-site requests, so an unexpected one is the signature of DNS
     * rebinding or a hostile page; the spec requires rejecting it with 403.
     *
     * Only the explicit allow-list counts. The site's "own" origin is not
     * trusted implicitly: without live_site Joomla derives it from the Host
     * header, and under DNS rebinding the attacker's page sends a Host that
     * matches its own Origin.
     *
     * @param  list<string>  $allowedOrigins
     */
    public static function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        return in_array($origin, $allowedOrigins, true);
    }

    /**
     * Status for an accepted notification. The spec says 202, but bridges
     * installed before 2026-07-28 support treat only 204 as "no body" and emit
     * an error frame for anything else. Every current client (2025-06-18 on,
     * and the new bridge) sends MCP-Protocol-Version, so its presence selects 202.
     *
     * @param  array<string, string>  $headers
     */
    public static function notificationStatus(array $headers): int
    {
        return isset($headers['mcp-protocol-version']) ? 202 : 204;
    }

    /**
     * HTTP status for a JSON-RPC response: the status RpcService attached to a
     * protocol-level outcome, else the legacy mapping of in-body error codes.
     *
     * @param  array<string, mixed>  $response
     */
    public static function responseStatus(?int $hint, array $response): int
    {
        if ($hint !== null) {
            return $hint;
        }

        return match ($response['error']['code'] ?? null) {
            JsonRpc::UNAUTHORIZED => 401,
            JsonRpc::RATE_LIMITED => 429,
            default => 200,
        };
    }

    /**
     * The request-log status for a response. A 400 is a protocol-level
     * rejection — the request's metadata never reached a method — recorded
     * like a header mismatch; any other error is a method's own failure.
     *
     * @param  array<string, mixed>  $response
     */
    public static function auditStatus(array $response, int $httpStatus, string $okStatus): string
    {
        if (!isset($response['error'])) {
            return $okStatus;
        }

        return $httpStatus === 400 ? 'invalid_request' : 'error';
    }

    /**
     * Server-Sent Events frames for a response stream, one `message` event per
     * JSON-RPC message, in order.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function sseFrames(array $messages): string
    {
        $frames = '';
        foreach ($messages as $message) {
            $frames .= "event: message\ndata: "
                . json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\n";
        }

        return $frames;
    }

    /**
     * Decode a header value that may use the `=?base64?…?=` sentinel for text
     * that is not plain visible ASCII.
     *
     * @throws McpProtocolError
     */
    public static function decodeHeaderValue(string $header, string $value): string
    {
        self::assertHeaderSafe($header, $value);

        if (!str_starts_with($value, self::BASE64_PREFIX) || !str_ends_with($value, self::BASE64_SUFFIX)) {
            return $value;
        }

        $encoded = substr($value, strlen(self::BASE64_PREFIX), -strlen(self::BASE64_SUFFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw self::mismatch("{$header} header carries malformed base64");
        }

        return $decoded;
    }

    /**
     * RFC 9110 field values: visible ASCII, space and horizontal tab.
     *
     * @throws McpProtocolError
     */
    private static function assertHeaderSafe(string $header, string $value): void
    {
        if (preg_match('/^[\x20-\x7E\t]*$/', $value) !== 1) {
            throw self::mismatch("{$header} header contains invalid characters");
        }
    }

    private static function mismatch(string $message): McpProtocolError
    {
        return new McpProtocolError($message, JsonRpc::HEADER_MISMATCH);
    }
}
