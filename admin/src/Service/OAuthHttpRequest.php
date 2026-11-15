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
 * The parts of an HTTP request the OAuth endpoints read, captured once from
 * Joomla's input (OAuthServiceFactory::currentRequest()) so OAuthEndpoints
 * never touches globals.
 */
final class OAuthHttpRequest
{
    /**
     * @param array<string,mixed> $query Selected query parameters, unfiltered.
     * @param array<string,mixed> $post  Selected form parameters, unfiltered.
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $body = '',
        public readonly string $authorization = '',
        public readonly string $clientIp = 'unknown',
    ) {
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function withQueryParam(string $name, string $value): self
    {
        return new self(
            $this->method,
            [$name => $value] + $this->query,
            $this->post,
            $this->body,
            $this->authorization,
            $this->clientIp,
        );
    }
}
