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
 * A complete HTTP response from an OAuth endpoint, built without touching
 * PHP's output so OAuthEndpoints stays testable; send() emits it.
 */
final class OAuthHttpResponse
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->headers + $headers, $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
