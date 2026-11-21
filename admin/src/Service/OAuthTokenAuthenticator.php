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
 * Resolves an OAuth access token to the grant behind it.
 *
 * This is only the first half of authenticating an OAuth request: the grant
 * names a Governed Mode credential, which GovernedAuthService then resolves
 * exactly as it would a credential bearer token (active, unexpired, unrevoked,
 * decryptable). The token itself never carries the Joomla API token.
 *
 * Audience (RFC 8707, MCP authorization): a token is only accepted at this
 * site's MCP endpoint in the site application, never at the administrator
 * endpoint, which no grant is issued for.
 */
final class OAuthTokenAuthenticator
{
    public const PREFIX = 'mcpat_';

    /**
     * The one message for every rejected OAuth access token, whichever check
     * failed, so callers cannot tell an unknown token from an expired one.
     */
    public const FAILURE = 'Invalid or expired OAuth access token';

    /**
     * @param string $requestUrl The URL the token was presented to.
     */
    public function __construct(
        private readonly OAuthStoreInterface $store,
        private readonly OAuthConfig $config,
        private readonly string $requestUrl,
    ) {
    }

    public static function isAccessToken(string $token): bool
    {
        return str_starts_with($token, self::PREFIX);
    }

    public function authenticate(string $token, DateTimeImmutable $now): OAuthTokenRecord
    {
        if (preg_match('/^' . self::PREFIX . '[A-Za-z0-9_-]{43}$/D', $token) !== 1 || !$this->config->ownsResource($this->requestUrl)) {
            throw new \RuntimeException(self::FAILURE);
        }

        $record = $this->store->findToken(OAuthServer::hash($token));

        if ($record === null || $record->type !== OAuthTokenRecord::ACCESS || !$record->isLive($now)
            || !$this->config->ownsResource($record->resource)) {
            throw new \RuntimeException(self::FAILURE);
        }

        return $record;
    }
}
