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
 * Persistence for the built-in OAuth authorisation server.
 *
 * Codes and tokens are only ever handed to the store as SHA-256 hashes; the
 * plaintext exists only in the response that delivers it to the client.
 */
interface OAuthStoreInterface
{
    public function createClient(OAuthClient $client): void;

    public function findClient(string $clientId): ?OAuthClient;

    /**
     * Overwrites a stored client's metadata, when its Client ID Metadata
     * Document has been fetched again.
     */
    public function replaceClient(OAuthClient $client): void;

    public function touchClient(string $clientId, DateTimeImmutable $now): void;

    /**
     * Records one OAuth grant: this user let this client act through this credential.
     *
     * @return int The new grant id.
     */
    public function createGrant(string $clientId, int $userId, int $credentialId, string $scope, string $resource, DateTimeImmutable $now): int;

    public function revokeGrant(int $grantId, DateTimeImmutable $now): void;

    public function createCode(int $grantId, string $codeHash, string $redirectUri, string $codeChallenge, DateTimeImmutable $expires): void;

    /**
     * Marks the code used and returns it. Must be atomic: of two concurrent
     * calls for the same hash, exactly one may see alreadyUsed = false.
     */
    public function consumeCode(string $codeHash, DateTimeImmutable $now): ?OAuthCodeRecord;

    public function createToken(int $grantId, string $type, string $tokenHash, DateTimeImmutable $expires, DateTimeImmutable $now): void;

    public function findToken(string $tokenHash): ?OAuthTokenRecord;

    /**
     * Revokes one token. Must be atomic.
     *
     * @return bool True if this call revoked it, false if it was already revoked.
     */
    public function revokeToken(int $tokenId, DateTimeImmutable $now): bool;

    /**
     * The credentials this user could bind a new grant to: their own, active,
     * unexpired and unrevoked.
     *
     * @return list<array{id:int,name:string,expires:?DateTimeImmutable}>
     */
    public function usableCredentials(int $userId, DateTimeImmutable $now): array;

    /**
     * Removes expired codes and tokens, and registered clients that never
     * completed an authorisation.
     */
    public function purge(DateTimeImmutable $now): void;
}
