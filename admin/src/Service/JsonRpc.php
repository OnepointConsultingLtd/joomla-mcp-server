<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

class JsonRpc
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    // -32000..-32019 is the grandfathered, implementation-defined sub-range;
    // these two predate MCP's allocation policy and are kept for compatibility.
    public const FORBIDDEN = -32000;
    public const UNAUTHORIZED = -32001;

    // Defined by the MCP specification (-32020..-32099 is reserved for it).
    public const HEADER_MISMATCH = -32020;
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    // Not -32002: MCP reserves that code (resource not found in 2025-11-25 and
    // earlier) and 2026-07-28 forbids emitting it. Application errors belong
    // outside the JSON-RPC reserved range (-32768..-32000).
    public const RATE_LIMITED = -31000;

    public static function errorResponse(mixed $id, int $code, string $message, array $details = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => $details,
            ],
        ];
    }

    public static function successResponse(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public static function parseRequest(string $body): ?array
    {
        return self::parseRequestData(json_decode($body, true));
    }

    /**
     * Validate an already-decoded JSON-RPC 2.0 request object.
     */
    public static function parseRequestData(mixed $payload): ?array
    {
        if (!is_array($payload) || !isset($payload['jsonrpc']) || $payload['jsonrpc'] !== '2.0') {
            return null;
        }
        // Absent for a client-sent response; when present it must be a string.
        if (array_key_exists('method', $payload) && !is_string($payload['method'])) {
            return null;
        }
        return $payload;
    }

    /**
     * True when the decoded body is a JSON-RPC batch (a non-empty JSON array).
     */
    public static function isBatch(mixed $payload): bool
    {
        return is_array($payload) && $payload !== [] && array_is_list($payload);
    }
}

