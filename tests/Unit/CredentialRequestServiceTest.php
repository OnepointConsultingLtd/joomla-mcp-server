<?php

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

    public function testApprovedOwnerCanClaimOnceWithoutStoringPendingApiToken(): void
    {
        $seed = base64_encode('request-owner-seed');
        $apiToken = base64_encode('sha256:42:' . hash_hmac('sha256', base64_decode($seed, true), 'site-secret'));
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store,
            new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase([$seed, '1']), static fn (): string => 'site-secret'),
            static fn (): int => self::NOW,
        );

        $id = $service->request(42, 'Claude Desktop');
        $this->assertSame('requested', $store->requests[$id]['status']);
        $service->approve($id, 7, true, self::NOW + 3600);
        $claimed = $service->claim($id, 42, 'Ada Lovelace', $apiToken);

        $this->assertSame('1', $claimed['id']);
        $this->assertSame('claimed', $store->requests[$id]['status']);
        $this->assertArrayNotHasKey('api_token', $store->requests[$id]);
        $this->assertArrayNotHasKey('api_token', $store->credentials[0]);
        $this->expectException(\RuntimeException::class);
        $service->claim($id, 42, 'Ada Lovelace', $apiToken);
    }

    public function testSuperUserCannotApproveOwnRequestAndExpiryMustBeFuture(): void
    {
        $store = new RequestServiceStore();
        $service = new CredentialRequestService(
            $store, new CredentialCipher('site-secret', base64_encode('component-salt-bytes-0123456789')),
            new JoomlaApiTokenOwnershipValidator(new RequestServiceDatabase(['', '0']), static fn (): string => 'site-secret'), static fn (): int => self::NOW,
        );
        $id = $service->request(42, 'Client');
        try { $service->approve($id, 42, true, self::NOW + 1); $this->fail('Self approval must fail'); } catch (\RuntimeException) { }
        $this->expectException(\InvalidArgumentException::class);
        $service->approve($id, 7, true, self::NOW);
    }
}
