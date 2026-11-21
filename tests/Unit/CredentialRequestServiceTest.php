<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestStoreInterface;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenOwnershipValidator;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class RequestServiceStore implements CredentialRequestStoreInterface
{
    /** @var array<string,array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    public array $requests = [];
    /** @var list<array<string,mixed>> */
    public array $credentials = [];

    public function create(int $userId, string $clientName, int $requestedAt): string { $id = (string) (count($this->requests) + 1); $this->requests[$id] = ['id' => $id, 'user_id' => $userId, 'client_name' => $clientName, 'status' => 'requested', 'credential_expires' => 0, 'credential_id' => null]; return $id; }
    public function find(string $id): ?array { return $this->requests[$id] ?? null; }
    public function listForUser(int $userId): array { return array_values(array_filter($this->requests, static fn (array $request): bool => $request['user_id'] === $userId)); }
    public function listPending(): array { return array_values(array_filter($this->requests, static fn (array $request): bool => $request['status'] === 'requested')); }
    public function decide(string $id, string $status, int $actorId, ?int $expiresAt, int $decidedAt): void { if ($this->requests[$id]['status'] !== 'requested') { throw new \RuntimeException('transition'); } $this->requests[$id]['status'] = $status; $this->requests[$id]['credential_expires'] = $expiresAt ?? 0; }
    public function claimWithCredential(string $id, array $credential, int $claimedAt): string { if ($this->requests[$id]['status'] !== 'approved') { throw new \RuntimeException('transition'); } $credentialId = (string) (count($this->credentials) + 1); $this->credentials[] = $credential; $this->requests[$id]['status'] = 'claimed'; $this->requests[$id]['credential_id'] = $credentialId; return $credentialId; }
}

final class RequestServiceQuery implements QueryInterface
{
    public function select(array|string $columns): self { return $this; } public function from(array|string $tables): self { return $this; } public function where(array|string $conditions, string $glue = 'AND'): self { return $this; } public function update(string $table): self { return $this; } public function set(array|string $values): self { return $this; } public function insert(string $table): self { return $this; } public function columns(array|string $columns): self { return $this; } public function values(array|string $values): self { return $this; } public function __toString(): string { return ''; }
}

final class RequestServiceDatabase implements DatabaseInterface
{
    /** @param list<string> $values */ public function __construct(private array $values) {}
    public function quoteName(array|string $name, array|string|null $alias = null): array|string { return is_array($name) ? $name : $name; } public function quote(array|string $text, bool $escape = true): array|string { return $text; } public function getQuery(bool $new = false): QueryInterface|string { return new RequestServiceQuery(); } public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self { return $this; } public function loadAssoc(): ?array { return null; } public function loadResult(): mixed { return array_shift($this->values); } public function execute(): bool { return true; }
}

final class CredentialRequestServiceTest extends TestCase
{
    public function testPersistenceRechecksApprovalExpiryAtClaimTransition(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../admin/src/Service/JoomlaCredentialRequestStore.php');

        $this->assertStringContainsString("quoteName('credential_expires')", $source);
        $this->assertStringContainsString("utc(\$claimedAt)", $source);
    }

    private const NOW = 1_700_000_000;

    /**
     * Known-good tokens for user 42, seed "seed-bytes", secret "site-secret",
     * in the format plg_user_token displays: base64("algo:userId:hmac").
     */
    private const SHA256_TOKEN_42 = 'c2hhMjU2OjQyOjExMjhmZDRjMmMxYzc1NjliYWI2ZTE0MzY2MmE5N2NmYTFlZWU3MGEyNDlmY2EwYjNmMDhhNzFjYTMwZTcwNTQ=';
    private const SHA512_TOKEN_42 = 'c2hhNTEyOjQyOmQzNzZjNWZiMGZiODEwYmIwYjdhZmY0OTdjYjZiOGE0NGNlYTFlNjZjMTIwZjJkNjA4OTE4ZGE3MGMwODQxODdkN2JjMDM2MTBiNDQ1MWM2MGVlZGUzMWZlMTFiNTg4ZGE0ZjEwYmU3ZWI5OGE3OTY0Y2Y1ZjNiNTdkNjUxOTY4';

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789'));
    }

    /** @param list<?string> $profile The owner's token seed, then their enabled flag. */
    private function service(RequestServiceStore $store, array $profile, string $secret = 'site-secret', string $algorithm = 'sha256'): CredentialRequestService
    {
        return new CredentialRequestService(
            $store,
            $this->cipher(),
            new JoomlaApiTokenOwnershipValidator(
                new RequestServiceDatabase($profile),
                static fn (): string => $secret,
                static fn (): string => $algorithm,
            ),
            static fn (): int => self::NOW,
        );
    }

    public function testApprovedOwnerClaimsOnceAndTheirOwnJoomlaApiTokenIsStored(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, [base64_encode('seed-bytes'), '1']);

        $id = $service->request(42, 'Claude Desktop');
        $this->assertSame('requested', $store->requests[$id]['status']);
        $service->approve($id, 7, true, self::NOW + 3600);
        $claimed = $service->claim($id, 42, 'Ada Lovelace');

        $this->assertSame('1', $claimed['id']);
        $this->assertStringStartsWith('mcp_', $claimed['bearer_token']);
        $this->assertSame('claimed', $store->requests[$id]['status']);
        $this->assertSame(self::SHA256_TOKEN_42, $this->cipher()->decrypt($store->credentials[0]['encrypted_token']));
        $this->assertArrayNotHasKey('api_token', $store->requests[$id]);
        $this->assertArrayNotHasKey('api_token', $store->credentials[0]);
        $this->expectException(\RuntimeException::class);
        $service->claim($id, 42, 'Ada Lovelace');
    }

    public function testClaimUsesTheAlgorithmTheSiteIssuesTokensWith(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, [base64_encode('seed-bytes'), '1'], algorithm: 'sha512');

        $id = $service->request(42, 'Claude Desktop');
        $service->approve($id, 7, true, self::NOW + 3600);
        $service->claim($id, 42, 'Ada Lovelace');

        $this->assertSame(self::SHA512_TOKEN_42, $this->cipher()->decrypt($store->credentials[0]['encrypted_token']));
    }

    /** @return array<string,array{0:list<?string>,1:string,2:string}> */
    public static function ownersWhoseTokenCannotBeWorkedOut(): array
    {
        return [
            'token disabled' => [[base64_encode('seed-bytes'), '0'], 'site-secret', 'is disabled'],
            'no token yet' => [[null, '1'], 'site-secret', 'has no Joomla API token yet'],
            'empty site secret' => [[base64_encode('seed-bytes'), '1'], '', 'application secret is empty'],
        ];
    }

    /**
     * @param list<?string> $profile
     * @dataProvider ownersWhoseTokenCannotBeWorkedOut
     */
    public function testClaimIsRefusedWithTheAccountProblemWhenNoTokenCanBeWorkedOut(array $profile, string $secret, string $message): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, $profile, $secret);
        $id = $service->request(42, 'Claude Desktop');
        $service->approve($id, 7, true, self::NOW + 3600);

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('The claim should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $store->credentials);
        $this->assertSame('approved', $store->requests[$id]['status']);
    }

    public function testAnApprovalWhoseExpiryHasPassedCannotBeClaimed(): void
    {
        $now = self::NOW;
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store,
            $this->cipher(),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase([base64_encode('seed-bytes'), '1']), static fn (): string => 'site-secret'),
            static function () use (&$now): int { return $now; },
        );
        $id = $service->request(42, 'Claude Desktop');
        $service->approve($id, 7, true, self::NOW + 3600);

        $now = self::NOW + 3600;

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('An expired approval should not be claimable');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame([], $store->credentials);
    }

    public function testOnlyTheOwnerCanClaimTheirRequest(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, [base64_encode('seed-bytes'), '1']);
        $id = $service->request(42, 'Claude Desktop');
        $service->approve($id, 7, true, self::NOW + 3600);

        $this->expectException(\RuntimeException::class);
        $service->claim($id, 99, 'Mallory');
    }

    public function testSuperUserCanApproveOwnRequest(): void
    {
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store, new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase(['', '0']), static fn (): string => 'site-secret'), static fn (): int => self::NOW,
        );
        $id = $service->request(42, 'Client');
        $service->approve($id, 42, true, self::NOW + 3600);
        $this->assertSame('approved', $store->find($id)['status']);
    }

    public function testSuperUserCanRejectOwnRequest(): void
    {
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store, new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase(['', '0']), static fn (): string => 'site-secret'), static fn (): int => self::NOW,
        );
        $id = $service->request(42, 'Client');
        $service->reject($id, 42, true);
        $this->assertSame('rejected', $store->find($id)['status']);
    }

    public function testApprovalExpiryMustBeFuture(): void
    {
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store, new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase(['', '0']), static fn (): string => 'site-secret'), static fn (): int => self::NOW,
        );
        $id = $service->request(42, 'Client');
        $this->expectException(\InvalidArgumentException::class);
        $service->approve($id, 7, true, self::NOW);
    }

    /**
     * A null expiry is the "never expires" grant. It must survive approval and
     * still be claimable — the claim path rechecks expiry, and a naive
     * `credential_expires <= now` guard would reject it, since SQL NULL maps to
     * 0 in the request metadata.
     */
    public function testApproverCanGrantANonExpiringCredentialAndItIsClaimable(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, [base64_encode('seed-bytes'), '1']);

        $id = $service->request(42, 'Build agent');
        $service->approve($id, 7, true, null);

        $this->assertSame(0, $store->requests[$id]['credential_expires'], 'never-expires is persisted as null/0');

        $claimed = $service->claim($id, 42, 'Ada Lovelace');

        $this->assertSame('1', $claimed['id']);
        $this->assertNull(
            $store->credentials[0]['expires_at'],
            'the stored credential must carry a null expiry, not epoch 0'
        );
    }

    public function testAnExpiryInThePastIsStillRejected(): void
    {
        // null means "never"; it must not weaken the check on a real timestamp.
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store, new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase(['', '0']), static fn (): string => 'site-secret'), static fn (): int => self::NOW,
        );
        $id = $service->request(42, 'Client');

        $this->expectException(\InvalidArgumentException::class);
        $service->approve($id, 7, true, self::NOW - 1);
    }
}
