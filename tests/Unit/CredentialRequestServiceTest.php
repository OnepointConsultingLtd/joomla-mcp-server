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
use Joomla\Component\Mcpserver\Administrator\Service\CredentialClaimRefusedException;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleStoreInterface;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestOAuthClaims;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestStoreInterface;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenAvailability;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenOwnershipValidator;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenProvisioner;
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
    public bool $failClaims = false;
    public function claimWithCredential(string $id, array $credential, int $claimedAt): string { if ($this->failClaims) { throw new \RuntimeException('database is down'); } if ($this->requests[$id]['status'] !== 'approved') { throw new \RuntimeException('transition'); } $credentialId = (string) (count($this->credentials) + 1); $this->credentials[] = $credential; $this->requests[$id]['status'] = 'claimed'; $this->requests[$id]['credential_id'] = $credentialId; return $credentialId; }
}

/**
 * A stand-in for #__user_profiles plus plg_user_token's extension row, reading
 * the where clauses the component builds so that reads see what earlier writes
 * stored. Lets a test prove a seed was really written, rather than queueing
 * the answer it expects.
 */
final class ProfileTableQuery implements QueryInterface
{
    public string $type = 'select';
    public string $table = '';
    /** @var list<string> */
    public array $where = [];
    /** @var list<string> */
    public array $values = [];

    public function select(array|string $columns): self { $this->type = 'select'; return $this; }
    public function from(array|string $tables): self { $this->table = (string) $tables; return $this; }
    public function where(array|string $conditions, string $glue = 'AND'): self { foreach ((array) $conditions as $c) { $this->where[] = $c; } return $this; }
    public function update(string $table): self { $this->type = 'update'; $this->table = $table; return $this; }
    public function set(array|string $values): self { return $this; }
    public function insert(string $table): self { $this->type = 'insert'; $this->table = $table; return $this; }
    public function delete(?string $table = null): self { $this->type = 'delete'; $this->table = (string) $table; return $this; }
    public function columns(array|string $columns): self { return $this; }
    public function values(array|string $values): self { foreach ((array) $values as $v) { $this->values[] = $v; } return $this; }
    public function __toString(): string { return ''; }
}

final class ProfileTableDatabase implements DatabaseInterface
{
    /** @var array<int,array<string,string>> user id => profile_key => profile_value */
    public array $profiles = [];
    public int $writes = 0;
    private ProfileTableQuery $query;
    /** @var array<int,array<string,string>>|null */
    private ?array $snapshot = null;

    /** @param array{extension_id:int,enabled:int,params:string}|null $tokenPlugin */
    public function __construct(public ?array $tokenPlugin = ['extension_id' => 401, 'enabled' => 1, 'params' => '{"allowedUserGroups":["2"]}'], public bool $policyLookupFails = false)
    {
        $this->query = new ProfileTableQuery();
    }

    public function quoteName(array|string $name, array|string|null $alias = null): array|string { return $name; }
    public function quote(array|string $text, bool $escape = true): array|string { return $text; }
    public function getQuery(bool $new = false): QueryInterface|string { return new ProfileTableQuery(); }
    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self { $this->query = $query; return $this; }

    public function loadAssoc(): ?array
    {
        if ($this->policyLookupFails) {
            throw new \RuntimeException('database is down');
        }

        return $this->tokenPlugin;
    }

    /** @return list<string> */
    public function loadColumn(int $offset = 0): array { return []; }

    public function loadResult(): mixed
    {
        [$userId, $key] = $this->target();

        return $this->profiles[$userId][$key] ?? null;
    }

    public function execute(): bool
    {
        $this->writes++;
        if ($this->query->type === 'delete') {
            [$userId, $key] = $this->target();
            $prefix = rtrim($key, '%');
            foreach (array_keys($this->profiles[$userId] ?? []) as $existing) {
                if ($key === $existing || ($prefix !== $key && str_starts_with($existing, $prefix))) {
                    unset($this->profiles[$userId][$existing]);
                }
            }
        }
        if ($this->query->type === 'insert') {
            foreach ($this->query->values as $row) {
                [$userId, $key, $value] = explode(', ', $row);
                $this->profiles[(int) $userId][$key] = $value;
            }
        }

        return true;
    }

    public function transactionStart(): void { $this->snapshot = $this->profiles; }
    public function transactionCommit(): void { $this->snapshot = null; }
    public function transactionRollback(): void { $this->profiles = $this->snapshot ?? $this->profiles; $this->snapshot = null; }

    /** @return array{0:int,1:string} */
    private function target(): array
    {
        $userId = 0;
        $key = '';
        foreach ($this->query->where as $condition) {
            if (preg_match('/^user_id = (\d+)$/', $condition, $m) === 1) {
                $userId = (int) $m[1];
            }
            if (preg_match('/^profile_key (?:=|LIKE) (.+)$/', $condition, $m) === 1) {
                $key = $m[1];
            }
        }

        return [$userId, $key];
    }
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

    /**
     * The owner's profile rows, as plg_user_token stores them. Null leaves a
     * row out.
     */
    private function owner(?string $seed, ?string $enabled): ProfileTableDatabase
    {
        $db = new ProfileTableDatabase();
        foreach (['joomlatoken.token' => $seed, 'joomlatoken.enabled' => $enabled] as $key => $value) {
            if ($value !== null) {
                $db->profiles[42][$key] = $value;
            }
        }

        return $db;
    }

    /**
     * @param list<int>           $groups The owner's groups; plg_user_token allows group 2 by default here.
     * @param (\Closure(): int)|null $clock
     */
    private function service(
        RequestServiceStore $store,
        ?ProfileTableDatabase $db = null,
        string $secret = 'site-secret',
        string $algorithm = 'sha256',
        array $groups = [2],
        ?\Closure $clock = null,
    ): CredentialRequestService {
        $db ??= $this->owner(null, null);

        return new CredentialRequestService(
            $store,
            $this->cipher(),
            new JoomlaApiTokenOwnershipValidator($db, static fn (): string => $secret, static fn (): string => $algorithm),
            new JoomlaApiTokenProvisioner(
                $db,
                new JoomlaApiTokenAvailability($db, static fn (int $userId): array => $groups),
                // "seed-bytes" is the seed the pinned tokens above are built from.
                static fn (int $length): string => 'seed-bytes',
            ),
            $clock ?? static fn (): int => self::NOW,
        );
    }

    private function approvedRequest(CredentialRequestService $service): string
    {
        $id = $service->request(42, 'Claude Desktop');
        $service->approve($id, 7, true, self::NOW + 3600);

        return $id;
    }

    public function testApprovedOwnerClaimsOnceAndTheirOwnJoomlaApiTokenIsStored(): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(base64_encode('seed-bytes'), '1');
        $service = $this->service($store, $db);

        $id = $service->request(42, 'Claude Desktop');
        $this->assertSame('requested', $store->requests[$id]['status']);
        $service->approve($id, 7, true, self::NOW + 3600);
        $claimed = $service->claim($id, 42, 'Ada Lovelace');

        $this->assertSame('1', $claimed['id']);
        $this->assertStringStartsWith('mcp_', $claimed['bearer_token']);
        $this->assertSame('claimed', $store->requests[$id]['status']);
        $this->assertSame(self::SHA256_TOKEN_42, $this->cipher()->decrypt($store->credentials[0]['encrypted_token']));
        $this->assertSame(0, $db->writes, 'an existing token is used as it is, never replaced');
        $this->assertArrayNotHasKey('api_token', $store->requests[$id]);
        $this->assertArrayNotHasKey('api_token', $store->credentials[0]);
        $this->expectException(\RuntimeException::class);
        $service->claim($id, 42, 'Ada Lovelace');
    }

    public function testClaimUsesTheAlgorithmTheSiteIssuesTokensWith(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, $this->owner(base64_encode('seed-bytes'), '1'), algorithm: 'sha512');

        $service->claim($this->approvedRequest($service), 42, 'Ada Lovelace');

        $this->assertSame(self::SHA512_TOKEN_42, $this->cipher()->decrypt($store->credentials[0]['encrypted_token']));
    }

    public function testAnOwnerWithNoTokenInAnAllowedGroupGetsOneCreatedAtClaim(): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $service = $this->service($store, $db);

        $service->claim($this->approvedRequest($service), 42, 'Ada Lovelace');

        // Exactly what plg_user_token writes when it creates a token.
        $this->assertSame(
            ['joomlatoken.token' => base64_encode('seed-bytes'), 'joomlatoken.enabled' => '1'],
            $db->profiles[42]
        );
        $this->assertSame(self::SHA256_TOKEN_42, $this->cipher()->decrypt($store->credentials[0]['encrypted_token']));
    }

    /** @return array<string,array{0:?string,1:?string,2:string,3:string}> */
    public static function ownersWhoseTokenCannotBeWorkedOut(): array
    {
        return [
            'token disabled' => [base64_encode('seed-bytes'), '0', 'site-secret', 'is disabled'],
            'switched off with no seed' => [null, '0', 'site-secret', 'is disabled'],
            'empty site secret' => [base64_encode('seed-bytes'), '1', '', 'application secret is empty'],
            'empty site secret, no seed' => [null, null, '', 'application secret is empty'],
            'unreadable seed' => ['not base64!', '1', 'site-secret', 'Reset it'],
        ];
    }

    /** @dataProvider ownersWhoseTokenCannotBeWorkedOut */
    public function testClaimIsRefusedWithTheAccountProblemWhenNoTokenCanBeWorkedOut(?string $seed, ?string $enabled, string $secret, string $message): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner($seed, $enabled);
        $before = $db->profiles;
        $service = $this->service($store, $db, $secret);
        $id = $this->approvedRequest($service);

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('The claim should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $store->credentials);
        $this->assertSame('approved', $store->requests[$id]['status']);
        $this->assertSame($before, $db->profiles, 'a refused claim leaves the profile alone; a disabled token is never switched back on');
    }

    /** @return array<string,array{0:list<int>,1:?array{extension_id:int,enabled:int,params:string},2:string}> */
    public static function ownersJoomlaWouldNotAllowAToken(): array
    {
        return [
            'group not allowed' => [[3], ['extension_id' => 401, 'enabled' => 1, 'params' => '{"allowedUserGroups":["2"]}'], 'Allowed User Groups'],
            'stock default (Super Users only)' => [[2], ['extension_id' => 401, 'enabled' => 1, 'params' => '{}'], 'Allowed User Groups'],
            'plugin disabled' => [[2], ['extension_id' => 401, 'enabled' => 0, 'params' => '{}'], 'not available on this site'],
            'plugin not installed' => [[2], null, 'not available on this site'],
        ];
    }

    /**
     * @param list<int> $groups
     * @param array{extension_id:int,enabled:int,params:string}|null $plugin
     * @dataProvider ownersJoomlaWouldNotAllowAToken
     */
    public function testAnOwnerJoomlaWouldNotAllowATokenIsRefusedAndNothingIsWritten(array $groups, ?array $plugin, string $message): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $db->tokenPlugin = $plugin;
        $service = $this->service($store, $db, groups: $groups);
        $id = $this->approvedRequest($service);

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('The claim should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(0, $db->writes);
        $this->assertSame([], $store->credentials);
    }

    public function testACreatedTokenStaysValidWhenTheClaimItselfFails(): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $service = $this->service($store, $db);
        $id = $this->approvedRequest($service);
        $store->failClaims = true;

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('The claim should have failed');
        } catch (\RuntimeException) {
        }

        // A whole, enabled token the owner can use or reset, not half a profile.
        $this->assertSame(
            ['joomlatoken.token' => base64_encode('seed-bytes'), 'joomlatoken.enabled' => '1'],
            $db->profiles[42]
        );
        $this->assertSame('approved', $store->requests[$id]['status']);
    }

    public function testNoSeedIsWrittenWhenTheTokenPolicyCannotBeChecked(): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $db->policyLookupFails = true;
        $service = $this->service($store, $db);
        $id = $this->approvedRequest($service);

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('The claim should have failed');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $db->writes, 'an unknown policy must never be read as "allowed"');
        $this->assertSame([], $store->credentials);
    }

    public function testAnApprovalWhoseExpiryHasPassedCannotBeClaimed(): void
    {
        $now = self::NOW;
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $service = $this->service($store, $db, clock: static function () use (&$now): int { return $now; });
        $id = $this->approvedRequest($service);

        $now = self::NOW + 3600;

        try {
            $service->claim($id, 42, 'Ada Lovelace');
            $this->fail('An expired approval should not be claimable');
        } catch (CredentialClaimRefusedException) {
        }
        $this->assertSame([], $store->credentials);
        $this->assertSame(0, $db->writes, 'no seed is created for a request that cannot be claimed');
    }

    public function testOnlyTheOwnerCanClaimTheirRequest(): void
    {
        $store = new RequestServiceStore();
        $db = $this->owner(null, null);
        $service = $this->service($store, $db);
        $id = $this->approvedRequest($service);

        try {
            $service->claim($id, 99, 'Mallory');
            $this->fail('Only the owner may claim');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $db->writes);
    }

    public function testOnlyTheOwnersApprovedUnclaimedUnexpiredRequestsAreClaimable(): void
    {
        $now = self::NOW;
        $store = new RequestServiceStore();
        $service = $this->service($store, $this->owner(base64_encode('seed-bytes'), '1'), clock: static function () use (&$now): int { return $now; });

        $pending = $service->request(42, 'Pending');
        $rejected = $service->request(42, 'Rejected');
        $service->reject($rejected, 7, true);
        $claimed = $this->approvedRequest($service);
        $service->claim($claimed, 42, 'Ada Lovelace');
        $expiring = $service->request(42, 'Expiring');
        $service->approve($expiring, 7, true, self::NOW + 60);
        $forever = $service->request(42, 'Forever');
        $service->approve($forever, 7, true, null);
        $someoneElses = $service->request(99, 'Not yours');
        $service->approve($someoneElses, 7, true, null);

        $this->assertSame(['Expiring', 'Forever'], array_column($service->listClaimable(42), 'client_name'));

        $now = self::NOW + 60;
        $this->assertSame(['Forever'], array_column($service->listClaimable(42), 'client_name'));
        $this->assertNotContains($pending, array_column($service->listClaimable(42), 'id'));
    }

    /**
     * The OAuth consent page claims through this adapter, so it must offer and
     * accept exactly what the credentials page would, and hand back only the
     * credential id: never the bearer token.
     */
    public function testTheConsentAdapterClaimsOnlyWhatTheServiceAllowsAndKeepsTheBearerToken(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store, $this->owner(base64_encode('seed-bytes'), '1'));
        $credentials = new class () implements CredentialLifecycleStoreInterface {
            /** @var list<string> */
            public array $revoked = [];
            public function save(array $record): string { return '1'; }
            public function listByOwner(int $ownerId): array { return []; }
            public function listAllMetadata(): array { return []; }
            public function findOwnership(string $id): ?array { return ['owner_id' => 42, 'revoked' => in_array($id, $this->revoked, true)]; }
            public function revoke(string $id): void { $this->revoked[] = $id; }
            public function deleteRevoked(string $id): void {}
            public function replace(array $record, string $revokedId): string { return '1'; }
        };
        $adapter = new CredentialRequestOAuthClaims($service, new CredentialLifecycleService($credentials, $this->cipher()));

        $pending = $service->request(42, 'Pending');
        $rejected = $service->request(42, 'Rejected');
        $service->reject($rejected, 7, true);
        $approved = $service->request(42, 'Claude Desktop');
        $service->approve($approved, 7, true, self::NOW + 3600);

        $offered = $adapter->claimable(42);
        $this->assertSame([[$approved, 'Claude Desktop']], array_map(static fn (array $r): array => [$r['id'], $r['name']], $offered));
        // NOW + 1 hour, in UTC.
        $this->assertSame('2023-11-14 23:13:20 UTC', $offered[0]['expires']?->format('Y-m-d H:i:s T'));

        foreach ([$pending, $rejected] as $notApproved) {
            try {
                $adapter->claim($notApproved, 42, 'ada');
                $this->fail('Only approved requests can be claimed at consent');
            } catch (CredentialClaimRefusedException) {
            }
        }

        $this->assertSame(1, $adapter->claim($approved, 42, 'ada'));
        $this->assertSame('claimed', $store->requests[$approved]['status']);
        $this->assertSame([], $adapter->claimable(42));

        $adapter->revoke(1, 42);
        $this->assertSame(['1'], $credentials->revoked);

        $this->expectException(CredentialClaimRefusedException::class);
        $adapter->claim($approved, 42, 'ada');
    }

    public function testSuperUserCanApproveOwnRequest(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store);
        $id = $service->request(42, 'Client');
        $service->approve($id, 42, true, self::NOW + 3600);
        $this->assertSame('approved', $store->find($id)['status']);
    }

    public function testSuperUserCanRejectOwnRequest(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store);
        $id = $service->request(42, 'Client');
        $service->reject($id, 42, true);
        $this->assertSame('rejected', $store->find($id)['status']);
    }

    public function testApprovalExpiryMustBeFuture(): void
    {
        $store = new RequestServiceStore();
        $service = $this->service($store);
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
        $service = $this->service($store, $this->owner(base64_encode('seed-bytes'), '1'));

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
        $service = $this->service($store);
        $id = $service->request(42, 'Client');

        $this->expectException(\InvalidArgumentException::class);
        $service->approve($id, 7, true, self::NOW - 1);
    }
}
