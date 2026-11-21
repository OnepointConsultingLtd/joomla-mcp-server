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
 * An issued access or refresh token, joined with the grant it belongs to and
 * the Governed Mode credential that grant is bound to.
 */
final class OAuthGrantTokenRecord
{
    public const ACCESS = 'access';
    public const REFRESH = 'refresh';

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly int $grantId,
        public readonly string $clientId,
        public readonly int $userId,
        public readonly int $credentialId,
        public readonly string $credentialSelector,
        public readonly string $scope,
        public readonly string $resource,
        public readonly DateTimeImmutable $expires,
        public readonly ?DateTimeImmutable $revoked,
        public readonly ?DateTimeImmutable $grantRevoked,
    ) {
    }

    public function isLive(DateTimeImmutable $now): bool
    {
        return $this->revoked === null && $this->grantRevoked === null && $this->expires > $now;
    }
}
