<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;

/**
 * An OAuth client: registered through Dynamic Client Registration (RFC 7591),
 * or identified by the Client ID Metadata Document at its https client_id.
 *
 * Every field except clientId and secretHash was supplied by the client and is
 * unverified. In particular clientName must only be shown as the client's own
 * claim, never as an identity this site vouches for. For a metadata-document
 * client the host of clientId is verified: the document was fetched from it.
 *
 * $created is when the client registered, or when its metadata document was
 * last fetched.
 */
final class OAuthClient
{
    public const AUTH_NONE = 'none';
    public const AUTH_SECRET_POST = 'client_secret_post';
    public const AUTH_SECRET_BASIC = 'client_secret_basic';

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly string $tokenEndpointAuthMethod,
        public readonly ?string $secretHash,
        public readonly DateTimeImmutable $created,
    ) {
    }

    public function isConfidential(): bool
    {
        return $this->tokenEndpointAuthMethod !== self::AUTH_NONE;
    }
}
