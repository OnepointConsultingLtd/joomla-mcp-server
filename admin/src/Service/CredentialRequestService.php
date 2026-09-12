<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Owns credential-request transitions. Requests never contain an API token;
 * the owner supplies and proves their current Joomla token only when claiming
 * an approved request.
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

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Request owner is required');
        }

        return $this->store->listForUser($userId);
    }

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    public function listPending(): array
    {
        return $this->store->listPending();
    }

    public function approve(string $id, int $actorId, bool $isSuperUser, int $expiresAt): void
    {
        $request = $this->requestedByAnotherUser($id, $actorId, $isSuperUser);
        $now = $this->now();
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException('Credential expiry must be in the future');
        }
        $this->store->decide($request['id'], 'approved', $actorId, $expiresAt, $now);
    }

    public function reject(string $id, int $actorId, bool $isSuperUser): void
    {
        $request = $this->requestedByAnotherUser($id, $actorId, $isSuperUser);
        $this->store->decide($request['id'], 'rejected', $actorId, null, $this->now());
    }

    /** @return array{id:string,bearer_token:string} */
    public function claim(string $id, int $userId, string $userName, string $apiToken): array
    {
        $request = $this->store->find($id);
        $now = $this->now();
        if ($request === null || $request['status'] !== 'approved' || $request['user_id'] !== $userId) {
            throw new \RuntimeException('Credential request is not available to claim');
        }
        if ($request['credential_expires'] <= $now || trim($userName) === '') {
            throw new \InvalidArgumentException('Credential request has expired or owner name is blank');
        }
        if (!$this->tokenValidator->belongsToUser($apiToken, $userId)) {
            throw new \RuntimeException('Joomla API token does not belong to the request owner');
        }

        $credential = McpCredential::issue();
        $credentialId = $this->store->claimWithCredential($request['id'], [
            'owner_id' => $userId,
            'owner_name' => trim($userName),
            'selector' => $credential['selector'],
            'verifier' => $credential['verifier'],
            'encrypted_token' => $this->cipher->encrypt($apiToken),
            'expires_at' => $request['credential_expires'],
            'created_at' => $now,
        ], $now);

        return ['id' => $credentialId, 'bearer_token' => $credential['token']];
    }

    /** @return array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string} */
    private function requestedByAnotherUser(string $id, int $actorId, bool $isSuperUser): array
    {
        if (!$isSuperUser) {
            throw new \RuntimeException('Only a Super User can decide credential requests');
        }
        $request = $this->store->find($id);
        if ($request === null || $request['status'] !== 'requested') {
            throw new \RuntimeException('Credential request is not awaiting a decision');
        }
        if ($request['user_id'] === $actorId) {
            throw new \RuntimeException('Super Users cannot decide their own credential requests');
        }

        return $request;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
