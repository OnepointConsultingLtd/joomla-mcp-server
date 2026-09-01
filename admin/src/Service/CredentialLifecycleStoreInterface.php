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
 * Persists MCP credential metadata and protected secret material.
 *
 * Implementations must never be given, and must never expose, a plaintext
 * bearer token or API token. Only the password verifier and the
 * {@see CredentialCipher}-encrypted API token are stored.
 */
interface CredentialLifecycleStoreInterface
{
    /**
     * Persist a newly issued credential.
     *
     * @param array{
     *     owner_id:int,
     *     owner_name:string,
     *     selector:string,
     *     verifier:string,
     *     encrypted_token:array{ciphertext:string,nonce:string,tag:string,key_version:int},
     *     expires_at:int,
     *     created_at:int
     * } $record
     * @return string Persisted credential identifier.
     */
    public function save(array $record): string;

    /**
     * List credential metadata for an owner. Must not include the verifier
     * or the encrypted API token.
     *
     * @return list<array{id:string,owner_id:int,owner_name:string,selector:string,expires_at:int,created_at:int,revoked:bool}>
     */
    public function listByOwner(int $ownerId): array;

    /**
     * Fetch the minimal ownership/state fields needed to authorize a revoke.
     *
     * @return array{id:string,owner_id:int,revoked:bool}|null
     */
    public function findOwnership(string $id): ?array;

    /**
     * Mark a credential as revoked. Must be idempotent for an already
     * revoked credential.
     */
    public function revoke(string $id): void;

    /**
     * Permanently delete a revoked credential record. Implementations must
     * fail if the credential is active so revocation remains the first step.
     */
    public function deleteRevoked(string $id): void;

    /**
     * Persist a replacement credential and revoke the credential it
     * replaces as a single atomic operation. Implementations must use a
     * database transaction when the underlying connection supports one.
     *
     * @param array{
     *     owner_id:int,
     *     owner_name:string,
     *     selector:string,
     *     verifier:string,
     *     encrypted_token:array{ciphertext:string,nonce:string,tag:string,key_version:int},
     *     expires_at:int,
     *     created_at:int
     * } $record
     * @return string Persisted replacement credential identifier.
     */
    public function replace(array $record, string $revokedId): string;
}
