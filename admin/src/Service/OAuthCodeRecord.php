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
 * An authorisation code as returned by OAuthStoreInterface::consumeCode().
 *
 * $alreadyUsed is true when the code had been redeemed before this call: the
 * caller must then refuse it and revoke the grant (RFC 6749 §4.1.2, OAuth 2.1
 * §4.1.3), because a second redemption means the code leaked.
 */
final class OAuthCodeRecord
{
    public function __construct(
        public readonly int $grantId,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly string $scope,
        public readonly string $resource,
        public readonly DateTimeImmutable $expires,
        public readonly bool $alreadyUsed,
    ) {
    }
}
