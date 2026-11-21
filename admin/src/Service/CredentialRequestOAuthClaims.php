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
use DateTimeZone;

/**
 * Claims approved credential requests for the OAuth consent page through the
 * same services the credentials page uses.
 */
final class CredentialRequestOAuthClaims implements OAuthCredentialClaimsInterface
{
    public function __construct(
        private readonly CredentialRequestService $requests,
        private readonly CredentialLifecycleService $credentials,
    ) {
    }

    public function claimable(int $userId): array
    {
        return array_map(
            static fn (array $request): array => [
                'id' => $request['id'],
                'name' => $request['client_name'],
                'expires' => $request['credential_expires'] === 0
                    ? null
                    : (new DateTimeImmutable('@' . $request['credential_expires']))->setTimezone(new DateTimeZone('UTC')),
            ],
            $this->requests->listClaimable($userId)
        );
    }

    public function claim(string $requestId, int $userId, string $userName): int
    {
        // The bearer token in the result is deliberately dropped here.
        return (int) $this->requests->claim($requestId, $userId, $userName)['id'];
    }

    public function revoke(int $credentialId, int $userId): void
    {
        $this->credentials->revoke((string) $credentialId, $userId);
    }
}
