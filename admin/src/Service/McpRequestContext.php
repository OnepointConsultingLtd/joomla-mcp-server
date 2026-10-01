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
 * The per-request protocol metadata a stateless (2026-07-28+) request carries
 * in params._meta. There is no handshake in that revision, so this is the only
 * place the server learns the version and client capabilities — and it must
 * not be remembered beyond the request it came with.
 */
final class McpRequestContext
{
    /**
     * @param  array<string, mixed>       $clientCapabilities
     * @param  array<string, mixed>|null  $clientInfo
     */
    public function __construct(
        public readonly string $protocolVersion,
        public readonly array $clientCapabilities,
        public readonly ?array $clientInfo = null,
        public readonly ?string $logLevel = null
    ) {
    }
}
