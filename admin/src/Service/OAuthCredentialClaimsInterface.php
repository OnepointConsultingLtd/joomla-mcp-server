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
 * What the OAuth consent page needs from the credential workflow to claim an
 * approved credential request on the spot. Implementations must go through
 * the credential request service, so a claim made at consent obeys exactly the
 * rules of one made on the credentials page.
 */
interface OAuthCredentialClaimsInterface
{
    /**
     * The user's approved credential requests that can be claimed right now.
     *
     * @return list<array{id:string,name:string,expires:?DateTimeImmutable}>
     */
    public function claimable(int $userId): array;

    /**
     * Claims the request and returns the new credential's id. The credential's
     * bearer token is discarded: an OAuth client never needs it.
     *
     * @throws CredentialClaimRefusedException With a message fit to show the owner.
     */
    public function claim(string $requestId, int $userId, string $userName): int;

    /** Revokes a credential claim() created, when the sign-in it was for failed. */
    public function revoke(int $credentialId, int $userId): void;
}
