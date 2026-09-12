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
 * Issues and parses opaque per-client MCP credentials.
 *
 * Only a password verifier is retained. The bearer token is returned to its
 * owner once and must never be written to logs or rendered again.
 */
final class McpCredential
{
    private const SELECTOR_BYTES = 12;

    private const SECRET_BYTES = 32;

    private const PREFIX = 'mcp_';

    /**
     * @return array{selector:string,secret:string,token:string,verifier:string}
     */
    public static function issue(): array
    {
        $selector = self::encode(random_bytes(self::SELECTOR_BYTES));
        $secret = self::encode(random_bytes(self::SECRET_BYTES));
        $verifier = password_hash($secret, PASSWORD_DEFAULT);
        if (!is_string($verifier)) {
            throw new \RuntimeException('Unable to protect MCP credential secret');
        }

        return [
            'selector' => $selector,
            'secret' => $secret,
            'token' => self::PREFIX . $selector . '.' . $secret,
            'verifier' => $verifier,
        ];
    }

    /**
     * @return array{selector:string,secret:string}|null
     */
    public static function parseBearer(string $header): ?array
    {
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);
        $pattern = '/^' . self::PREFIX . '([A-Za-z0-9_-]{16})\.([A-Za-z0-9_-]{43})$/D';
        if (preg_match($pattern, $token, $matches) !== 1) {
            return null;
        }

        return ['selector' => $matches[1], 'secret' => $matches[2]];
    }

    public static function verify(string $secret, string $verifier): bool
    {
        return password_verify($secret, $verifier);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
