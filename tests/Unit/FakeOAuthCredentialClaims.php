<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\OAuthCredentialClaimsInterface;

/**
 * Approved credential requests the tests control. Claiming one adds a usable
 * credential to the OAuth store, as the real credential request service does
 * through the database; revoking it makes that credential unusable.
 */
final class FakeOAuthCredentialClaims implements OAuthCredentialClaimsInterface
{
    /** @var array<string,array{user_id:int,name:string}> */
    public array $approved = [];

    /** @var list<string> Request ids claimed, in order. */
    public array $claimed = [];

    /** @var list<int> Credential ids revoked, in order. */
    public array $revoked = [];

    /** A refusal to raise from claim(), as the credential request service would. */
    public ?\Throwable $refusal = null;

    private int $nextCredentialId = 100;

    public function __construct(private readonly InMemoryOAuthStore $store)
    {
    }

    public function approve(string $requestId, int $userId, string $name): void
    {
        $this->approved[$requestId] = ['user_id' => $userId, 'name' => $name];
    }

    public function claimable(int $userId): array
    {
        $claimable = [];
        foreach ($this->approved as $id => $request) {
            if ($request['user_id'] === $userId && !in_array((string) $id, $this->claimed, true)) {
                $claimable[] = ['id' => (string) $id, 'name' => $request['name'], 'expires' => null];
            }
        }

        return $claimable;
    }

    public function claim(string $requestId, int $userId, string $userName): int
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        if (($this->approved[$requestId]['user_id'] ?? null) !== $userId || in_array($requestId, $this->claimed, true)) {
            throw new \RuntimeException('Credential request is not available to claim');
        }

        $this->claimed[] = $requestId;
        $credentialId = $this->nextCredentialId++;
        $this->store->addCredential($credentialId, $userId, 'selector-' . $credentialId, $this->approved[$requestId]['name']);

        return $credentialId;
    }

    public function revoke(int $credentialId, int $userId): void
    {
        $this->revoked[] = $credentialId;
        $this->store->credentials[$credentialId]['usable'] = false;
    }
}
