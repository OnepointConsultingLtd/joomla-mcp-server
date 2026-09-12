<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

interface CredentialRequestStoreInterface
{
    public function create(int $userId, string $clientName, int $requestedAt): string;

    /** @return array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}|null */
    public function find(string $id): ?array;

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    public function listForUser(int $userId): array;

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    public function listPending(): array;

    public function decide(string $id, string $status, int $actorId, ?int $expiresAt, int $decidedAt): void;

    /**
     * Atomically persists a protected credential and transitions the approved request to claimed.
     *
     * @param array{owner_id:int,owner_name:string,selector:string,verifier:string,encrypted_token:array{ciphertext:string,nonce:string,tag:string,key_version:int},expires_at:int,created_at:int} $credential
     */
    public function claimWithCredential(string $id, array $credential, int $claimedAt): string;
}
