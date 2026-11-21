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
        private readonly JoomlaApiTokenProvisioner $tokenProvisioner,
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

    /**
     * The owner's requests that claim() would accept right now: approved, not
     * yet claimed, and not past the expiry the approver set.
     *
     * @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string,username:?string,user_name:?string}>
     */
    public function listClaimable(int $userId): array
    {
        $now = $this->now();

        return array_values(array_filter(
            $this->listForUser($userId),
            static fn (array $request): bool => $request['status'] === 'approved'
                && $request['user_id'] === $userId
                && !self::approvalHasExpired($request, $now)
        ));
    }

    /** @return array{id:string,bearer_token:string} */
    public function claim(string $id, int $userId, string $userName): array
    {
        $request = $this->store->find($id);
        $now = $this->now();
        if ($request === null || $request['status'] !== 'approved' || $request['user_id'] !== $userId) {
            throw new CredentialClaimRefusedException('Credential request is not available to claim');
        }
        $neverExpires = $request['credential_expires'] === 0;

        if (self::approvalHasExpired($request, $now) || trim($userName) === '') {
            throw new CredentialClaimRefusedException('Credential request has expired or owner name is blank');
        }
        // The owner never handles the token: it is worked out from their own
        // account, so it cannot be someone else's or the shared one.
        $derived = $this->tokenValidator->currentToken($userId);
        if ($derived['reason'] === JoomlaApiTokenOwnershipValidator::REASON_NO_SEED) {
            // An owner who never had a token gets one, as Joomla would give
            // them on saving their profile, if Joomla allows them one at all.
            $provisioned = $this->tokenProvisioner->provision($userId);
            if ($provisioned !== JoomlaApiTokenProvisioner::OK) {
                throw new CredentialClaimRefusedException(self::tokenRejectionMessage($provisioned));
            }
            $derived = $this->tokenValidator->currentToken($userId);
        }
        if ($derived['token'] === null) {
            // Say which account problem it is: they have entirely different fixes.
            throw new CredentialClaimRefusedException(self::tokenRejectionMessage($derived['reason']));
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

    /**
     * credential_expires === 0 means the approver granted a credential that
     * never expires (stored as SQL NULL); only a real timestamp in the past
     * makes an approved request unclaimable. The request's own status
     * separates that from "not decided yet", which is also 0.
     *
     * @param array{credential_expires:int} $request
     */
    private static function approvalHasExpired(array $request, int $now): bool
    {
        return $request['credential_expires'] !== 0 && $request['credential_expires'] <= $now;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    /**
     * Map a reason from the token validator or provisioner to an actionable
     * message.
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
            JoomlaApiTokenProvisioner::REASON_GROUP_NOT_ALLOWED =>
                'Your user group is not allowed to hold a Joomla API token, so this credential cannot be claimed yet. '
                . 'A Super User must add your group to System -> Plugins -> "User - Joomla API Token" -> '
                . 'Allowed User Groups; by default only Super Users are permitted.',
            JoomlaApiTokenProvisioner::REASON_PLUGIN_DISABLED =>
                'Joomla API tokens are not available on this site, so this credential cannot be claimed yet. '
                . 'A Super User must make sure the "User - Joomla API Token" plugin is installed and enabled '
                . 'under System -> Plugins.',
            // Only reachable when a seed exists but cannot be read: an owner
            // with no seed at all has one created, or is told why not.
            JoomlaApiTokenOwnershipValidator::REASON_NO_SEED =>
                'Your account\'s Joomla API token could not be read. Reset it under '
                . 'Users -> Manage -> [your account] -> API Tokens, then claim again.',
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
