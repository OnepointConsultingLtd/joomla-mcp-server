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
 * Owns credential-request transitions. Requests never contain an API token;
 * claiming an approved request binds the owner's current Joomla API token,
 * which the server works out from their account rather than asking for it.
 */
final class CredentialRequestService
{
    /** @param callable(): int $clock */
    public function __construct(
        private readonly CredentialRequestStoreInterface $store,
        private readonly CredentialCipher $cipher,
        private readonly JoomlaApiTokenOwnershipValidator $tokenValidator,
        private $clock,
    ) {
    }

    public function request(int $userId, string $clientName): string
    {
        if ($userId <= 0 || trim($clientName) === '') {
            throw new \InvalidArgumentException('Request owner and client name are required');
        }

        return $this->store->create($userId, trim($clientName), $this->now());
    }

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string,username:?string,user_name:?string}> */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Request owner is required');
        }

        return $this->store->listForUser($userId);
    }

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string,username:?string,user_name:?string}> */
    public function listPending(): array
    {
        return $this->store->listPending();
    }

    /**
     * @param int|null $expiresAt Absolute expiry, or null for a credential that
     *                            never expires. A non-expiring credential can
     *                            still be revoked, but nothing forces it to be
     *                            rotated, so it is deliberately an explicit
     *                            choice by the approver rather than a default.
     */
    public function approve(string $id, int $actorId, bool $isSuperUser, ?int $expiresAt): void
    {
        $request = $this->pendingRequestForSuperUser($id, $isSuperUser);
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new \InvalidArgumentException('Credential expiry must be in the future');
        }
        $this->store->decide($request['id'], 'approved', $actorId, $expiresAt, $now);
    }

    public function reject(string $id, int $actorId, bool $isSuperUser): void
    {
        $request = $this->pendingRequestForSuperUser($id, $isSuperUser);
        $this->store->decide($request['id'], 'rejected', $actorId, null, $this->now());
    }

    /** @return array{id:string,bearer_token:string} */
    public function claim(string $id, int $userId, string $userName): array
    {
        $request = $this->store->find($id);
        $now = $this->now();
        if ($request === null || $request['status'] !== 'approved' || $request['user_id'] !== $userId) {
            throw new \RuntimeException('Credential request is not available to claim');
        }
        // credential_expires === 0 means the approver granted a credential that
        // never expires (stored as SQL NULL); only a real timestamp in the past
        // makes the request unclaimable. The request's own status separates
        // that from "not decided yet", which is also 0.
        $neverExpires = $request['credential_expires'] === 0;

        if ((!$neverExpires && $request['credential_expires'] <= $now) || trim($userName) === '') {
            throw new \InvalidArgumentException('Credential request has expired or owner name is blank');
        }
        // The owner never handles the token: it is worked out from their own
        // account, so it cannot be someone else's or the shared one.
        $derived = $this->tokenValidator->currentToken($userId);
        if ($derived['token'] === null) {
            // Say which account problem it is: they have entirely different fixes.
            throw new \RuntimeException(self::tokenRejectionMessage($derived['reason']));
        }
        $apiToken = $derived['token'];

        $credential = McpCredential::issue();
        $credentialId = $this->store->claimWithCredential($request['id'], [
            'owner_id' => $userId,
            'owner_name' => trim($userName),
            'selector' => $credential['selector'],
            'verifier' => $credential['verifier'],
            'encrypted_token' => $this->cipher->encrypt($apiToken),
            'expires_at' => $neverExpires ? null : $request['credential_expires'],
            'created_at' => $now,
        ], $now);

        return ['id' => $credentialId, 'bearer_token' => $credential['token']];
    }

    /** @return array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string} */
    /**
     * A Super User may decide any pending request, including their own: on a
     * site with a single administrator there is nobody else to approve it.
     */
    private function pendingRequestForSuperUser(string $id, bool $isSuperUser): array
    {
        if (!$isSuperUser) {
            throw new \RuntimeException('Only a Super User can decide credential requests');
        }
        $request = $this->store->find($id);
        if ($request === null || $request['status'] !== 'requested') {
            throw new \RuntimeException('Credential request is not awaiting a decision');
        }

        return $request;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    /**
     * Map a validator reason code to an actionable message.
     *
     * Plain English rather than language keys: this service is also reachable
     * outside an administrator request, and the controller renders whatever the
     * exception carries.
     */
    private static function tokenRejectionMessage(string $reason): string
    {
        return match ($reason) {
            JoomlaApiTokenOwnershipValidator::REASON_NOT_ENABLED =>
                'Your account\'s Joomla API token is disabled. Enable it under '
                . 'Users -> Manage -> [your account] -> API Tokens, then claim again.',
            JoomlaApiTokenOwnershipValidator::REASON_NO_SEED =>
                'Your account has no Joomla API token yet. Open your account and use the API Tokens tab to create one, '
                . 'then claim again. If that tab is missing, a Super User must add your user group to the '
                . '"User - Joomla API Token" plugin under System -> Plugins -> Allowed User Groups; by default only '
                . 'Super Users are permitted.',
            JoomlaApiTokenOwnershipValidator::REASON_ALGORITHM =>
                'This site\'s "User - Joomla API Token" plugin is set to an HMAC algorithm Joomla does not accept. '
                . 'A Super User must set it to sha256 or sha512.',
            JoomlaApiTokenOwnershipValidator::REASON_NO_SECRET =>
                'The Joomla application secret is empty, so no Joomla API token can be worked out. '
                . 'A Super User must set the "secret" value in configuration.php.',
            default =>
                'Your account\'s Joomla API token could not be worked out. Try again, and ask a Super User '
                . 'if the problem continues.',
        };
    }
}
