<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use DateTimeImmutable;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthClient;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthCodeRecord;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthStoreInterface;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthTokenRecord;

/**
 * OAuthStoreInterface in memory, with credentials the tests control.
 */
final class InMemoryOAuthStore implements OAuthStoreInterface
{
    /** @var array<string,OAuthClient> */
    public array $clients = [];

    /** @var array<int,array{client_id:string,user_id:int,credential_id:int,scope:string,resource:string,revoked:?DateTimeImmutable}> */
    public array $grants = [];

    /** @var array<string,array{grant_id:int,redirect_uri:string,challenge:string,expires:DateTimeImmutable,used:bool}> */
    public array $codes = [];

    /** @var array<int,array{hash:string,grant_id:int,type:string,expires:DateTimeImmutable,revoked:?DateTimeImmutable}> */
    public array $tokens = [];

    /** @var array<int,array{id:int,user_id:int,name:string,selector:string,usable:bool}> */
    public array $credentials = [];

    public int $purges = 0;

    public function addCredential(int $id, int $userId, string $selector, string $name = 'Laptop'): void
    {
        $this->credentials[$id] = ['id' => $id, 'user_id' => $userId, 'name' => $name, 'selector' => $selector, 'usable' => true];
    }

    public function createClient(OAuthClient $client): void
    {
        $this->clients[$client->clientId] = $client;
    }

    public function replaceClient(OAuthClient $client): void
    {
        $this->clients[$client->clientId] = $client;
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        return $this->clients[$clientId] ?? null;
    }

    public function touchClient(string $clientId, DateTimeImmutable $now): void
    {
    }

    public function createGrant(string $clientId, int $userId, int $credentialId, string $scope, string $resource, DateTimeImmutable $now): int
    {
        $id = count($this->grants) + 1;
        $this->grants[$id] = [
            'client_id' => $clientId,
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'scope' => $scope,
            'resource' => $resource,
            'revoked' => null,
        ];

        return $id;
    }

    public function revokeGrant(int $grantId, DateTimeImmutable $now): void
    {
        $this->grants[$grantId]['revoked'] ??= $now;
    }

    public function createCode(int $grantId, string $codeHash, string $redirectUri, string $codeChallenge, DateTimeImmutable $expires): void
    {
        $this->codes[$codeHash] = ['grant_id' => $grantId, 'redirect_uri' => $redirectUri, 'challenge' => $codeChallenge, 'expires' => $expires, 'used' => false];
    }

    public function consumeCode(string $codeHash, DateTimeImmutable $now): ?OAuthCodeRecord
    {
        if (!isset($this->codes[$codeHash])) {
            return null;
        }

        $code = $this->codes[$codeHash];
        $grant = $this->grants[$code['grant_id']];
        $this->codes[$codeHash]['used'] = true;

        return new OAuthCodeRecord(
            $code['grant_id'],
            $grant['client_id'],
            $code['redirect_uri'],
            $code['challenge'],
            $grant['scope'],
            $grant['resource'],
            $code['expires'],
            $code['used'],
        );
    }

    public function createToken(int $grantId, string $type, string $tokenHash, DateTimeImmutable $expires, DateTimeImmutable $now): void
    {
        $id = count($this->tokens) + 1;
        $this->tokens[$id] = ['hash' => $tokenHash, 'grant_id' => $grantId, 'type' => $type, 'expires' => $expires, 'revoked' => null];
    }

    public function findToken(string $tokenHash): ?OAuthTokenRecord
    {
        foreach ($this->tokens as $id => $token) {
            if ($token['hash'] !== $tokenHash) {
                continue;
            }

            $grant = $this->grants[$token['grant_id']];
            $credential = $this->credentials[$grant['credential_id']] ?? null;
            if ($credential === null) {
                return null;
            }

            return new OAuthTokenRecord(
                $id,
                $token['type'],
                $token['grant_id'],
                $grant['client_id'],
                $grant['user_id'],
                $grant['credential_id'],
                $credential['selector'],
                $grant['scope'],
                $grant['resource'],
                $token['expires'],
                $token['revoked'],
                $grant['revoked'],
            );
        }

        return null;
    }

    public function revokeToken(int $tokenId, DateTimeImmutable $now): bool
    {
        if ($this->tokens[$tokenId]['revoked'] !== null) {
            return false;
        }

        $this->tokens[$tokenId]['revoked'] = $now;

        return true;
    }

    public function usableCredentials(int $userId, DateTimeImmutable $now): array
    {
        $usable = [];
        foreach ($this->credentials as $credential) {
            if ($credential['user_id'] === $userId && $credential['usable']) {
                $usable[] = ['id' => $credential['id'], 'name' => $credential['name'], 'expires' => null];
            }
        }

        return $usable;
    }

    public function purge(DateTimeImmutable $now): void
    {
        ++$this->purges;
    }
}
