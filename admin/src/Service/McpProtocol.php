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
 * Protocol-version knowledge for a dual-era MCP server.
 *
 * "Modern" revisions (2026-07-28 onward) are stateless: every request declares
 * its version and client capabilities in params._meta and there is no
 * initialize handshake. "Legacy" revisions negotiate once through initialize.
 * The server serves both, and decides per request — never per connection —
 * which semantics apply.
 */
final class McpProtocol
{
    public const MODERN_VERSIONS = ['2026-07-28'];

    /** Newest first: the head of the list is initialize's fallback. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    public const META_LOG_LEVEL = 'io.modelcontextprotocol/logLevel';
    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    public const META_SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

    /** RFC 5424 severities, the only values _meta logLevel may carry. */
    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Freshness hint for the catalogue results (tools, prompts, templates,
     * discovery). They only change on an upgrade or an Options change, and the
     * server advertises no listChanged notifications to invalidate them sooner.
     */
    public const CATALOGUE_TTL_MS = 300000;

    /** Identical for every caller: safe for a shared cache. */
    private const PUBLIC_CACHEABLE_METHODS = ['server/discover', 'tools/list', 'prompts/list', 'resources/templates/list'];

    /**
     * Article content is fetched with the caller's own Joomla API token in
     * Governed Mode, so what one user may read must never be served to another
     * from a shared cache.
     */
    private const PRIVATE_CACHEABLE_METHODS = ['resources/list', 'resources/read'];

    /**
     * @return list<string>
     */
    public static function supportedVersions(): array
    {
        return [...self::MODERN_VERSIONS, ...self::LEGACY_VERSIONS];
    }

    public static function isSupported(mixed $version): bool
    {
        return is_string($version) && in_array($version, self::supportedVersions(), true);
    }

    public static function isModern(mixed $version): bool
    {
        return is_string($version) && in_array($version, self::MODERN_VERSIONS, true);
    }

    /**
     * The version to answer an initialize request with. initialize is the
     * legacy handshake, so it can never select a modern revision: a client
     * asking for one (or for anything unknown) gets the newest legacy version.
     */
    public static function negotiateLegacy(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::LEGACY_VERSIONS, true)
            ? $requested
            : self::LEGACY_VERSIONS[0];
    }

    /**
     * True when the request body declares a modern protocol version.
     *
     * @param  array<string, mixed>  $request
     */
    public static function isModernRequest(array $request): bool
    {
        $params = $request['params'] ?? null;
        $meta = is_array($params) ? ($params['_meta'] ?? null) : null;

        return is_array($meta) && self::isModern($meta[self::META_PROTOCOL_VERSION] ?? null);
    }

    /**
     * Classify a request and validate its per-request metadata.
     *
     * Returns null for legacy semantics: a notification (notifications carry no
     * request metadata), initialize, a request without a declared version —
     * legacy clients send _meta too, for progress tokens — or one declaring a
     * legacy version, which the server can serve statelessly because it never
     * kept handshake state in the first place.
     *
     * @param  array<string, mixed>  $request
     *
     * @throws McpProtocolError when a modern request's metadata is unusable
     */
    public static function contextFor(array $request): ?McpRequestContext
    {
        if (!array_key_exists('id', $request) || ($request['method'] ?? null) === 'initialize') {
            return null;
        }

        $params = $request['params'] ?? null;
        $meta = is_array($params) ? ($params['_meta'] ?? null) : null;
        if (!is_array($meta) || !array_key_exists(self::META_PROTOCOL_VERSION, $meta)) {
            return null;
        }

        $version = $meta[self::META_PROTOCOL_VERSION];
        if (!self::isSupported($version)) {
            throw self::unsupportedVersion(is_string($version) ? $version : (string) json_encode($version));
        }

        if (!self::isModern($version)) {
            return null;
        }

        if (!array_key_exists(self::META_CLIENT_CAPABILITIES, $meta) || !self::isJsonObject($meta[self::META_CLIENT_CAPABILITIES])) {
            throw new McpProtocolError(
                'Missing or invalid required _meta field ' . self::META_CLIENT_CAPABILITIES . ' (must be an object)',
                JsonRpc::INVALID_PARAMS
            );
        }

        $clientInfo = $meta[self::META_CLIENT_INFO] ?? null;
        if ($clientInfo !== null && !self::isJsonObject($clientInfo)) {
            throw new McpProtocolError('Invalid _meta field ' . self::META_CLIENT_INFO . ' (must be an object)', JsonRpc::INVALID_PARAMS);
        }

        $logLevel = $meta[self::META_LOG_LEVEL] ?? null;
        if ($logLevel !== null && !in_array($logLevel, self::LOG_LEVELS, true)) {
            throw new McpProtocolError('Invalid _meta field ' . self::META_LOG_LEVEL, JsonRpc::INVALID_PARAMS);
        }

        if ($request['id'] === null) {
            throw new McpProtocolError('Request id must not be null', JsonRpc::INVALID_REQUEST);
        }

        return new McpRequestContext($version, $meta[self::META_CLIENT_CAPABILITIES], $clientInfo, $logLevel);
    }

    public static function unsupportedVersion(string $requested): McpProtocolError
    {
        return new McpProtocolError('Unsupported protocol version', JsonRpc::UNSUPPORTED_PROTOCOL_VERSION, [
            'supported' => self::supportedVersions(),
            'requested' => $requested,
        ]);
    }

    /**
     * Add the fields every modern result carries: resultType, the server's
     * identity in _meta and, for cacheable methods, the caching hints.
     *
     * @param  array<string, mixed>                $result
     * @param  array{name: string, version: string} $serverInfo
     * @return array<string, mixed>
     */
    public static function completeResult(string $method, array $result, array $serverInfo, int $contentTtlMs): array
    {
        $result['resultType'] ??= 'complete';

        $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
        $meta[self::META_SERVER_INFO] = $serverInfo;
        $result['_meta'] = $meta;

        if (in_array($method, self::PUBLIC_CACHEABLE_METHODS, true)) {
            $result['ttlMs'] = self::CATALOGUE_TTL_MS;
            $result['cacheScope'] = 'public';
        } elseif (in_array($method, self::PRIVATE_CACHEABLE_METHODS, true)) {
            $result['ttlMs'] = max(0, $contentTtlMs);
            $result['cacheScope'] = 'private';
        }

        return $result;
    }

    /**
     * A decoded JSON object. Bodies are decoded to associative arrays, so `{}`
     * arrives as [] and is indistinguishable from `[]`; both are accepted, but a
     * non-empty list is not an object.
     */
    private static function isJsonObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
