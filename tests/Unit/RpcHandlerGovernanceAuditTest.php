<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Controller\RpcHandlerTrait;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaActionLogService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;

final class RpcHandlerGovernanceAuditTestHost
{
    use RpcHandlerTrait;
}

final class RpcHandlerGovernanceAuditTestContainer implements ContainerInterface
{
    /**
     * @param  array<class-string, object>  $services
     */
    public function __construct(private array $services)
    {
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }

    public function get(string $id)
    {
        return $this->services[$id];
    }
}

final class RpcAuditFakeQuery implements QueryInterface
{
    /** @var list<string> */
    public array $insertColumns = [];
    public string $insertValues = '';
    public string $insertTable = '';

    public function select(array|string $columns): self
    {
        return $this;
    }

    public function from(array|string $tables): self
    {
        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        return $this;
    }

    public function update(string $table): self
    {
        return $this;
    }

    public function set(array|string $values): self
    {
        return $this;
    }

    public function insert(string $table): self
    {
        $this->insertTable = $table;

        return $this;
    }

    public function columns(array|string $columns): self
    {
        $this->insertColumns = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    public function values(array|string $values): self
    {
        $this->insertValues = is_array($values) ? implode(',', $values) : $values;

        return $this;
    }

    public function __toString(): string
    {
        return "INSERT INTO {$this->insertTable} (" . implode(',', $this->insertColumns) . ') VALUES (' . $this->insertValues . ')';
    }
}

final class RpcAuditFakeDatabase implements DatabaseInterface
{
    public ?RpcAuditFakeQuery $lastQuery = null;
    public bool $executed = false;
    public bool $throwOnExecute = false;

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        if (is_array($name)) {
            return array_map(static fn (string $n): string => '`' . $n . '`', $name);
        }

        return '`' . $name . '`';
    }

    public function quote(array|string $text, bool $escape = true): array|string
    {
        if (is_array($text)) {
            return array_map(static fn (string $t): string => "'" . addslashes($t) . "'", $text);
        }

        return "'" . addslashes($text) . "'";
    }

    public function getQuery(bool $new = false): QueryInterface|string
    {
        return new RpcAuditFakeQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query instanceof RpcAuditFakeQuery ? $query : null;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return null;
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function execute(): bool
    {
        if ($this->throwOnExecute) {
            throw new \RuntimeException('audit table unavailable');
        }

        $this->executed = true;

        return true;
    }
}

/**
 * Covers RpcHandlerTrait::recordGovernanceAudit(): the boundary that decides
 * whether a governed request is written to the audit trail and, separately,
 * whether a successful mutating tool call also produces a Joomla Action Log
 * entry.
 */
final class RpcHandlerGovernanceAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        $property = new ReflectionProperty(McpserverComponent::class, 'serviceContainer');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function principal(int $userId = 42): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(
            credentialId: 7,
            selector: 'sel-abc',
            userId: $userId,
            credentialName: 'CI Token',
            joomlaApiToken: 'super-secret-token-value',
        );
    }

    /**
     * @return array{host: RpcHandlerGovernanceAuditTestHost, db: RpcAuditFakeDatabase, calls: \ArrayObject}
     */
    private function boot(?callable $writer = null): array
    {
        $db = new RpcAuditFakeDatabase();
        $audit = new GovernanceAuditService(
            $db,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-08-20 12:00:00')
        );

        $calls = new \ArrayObject();
        $writer ??= static function (int $userId, string $messageKey, string $context, array $message) use ($calls): void {
            $calls[] = ['userId' => $userId, 'messageKey' => $messageKey, 'context' => $context, 'message' => $message];
        };
        $actionLog = new JoomlaActionLogService($writer);

        $container = new RpcHandlerGovernanceAuditTestContainer([
            GovernanceAuditService::class => $audit,
            JoomlaActionLogService::class => $actionLog,
        ]);

        (new McpserverComponent())->boot($container);

        return ['host' => new RpcHandlerGovernanceAuditTestHost(), 'db' => $db, 'calls' => $calls];
    }

    private function recordGovernanceAudit(
        RpcHandlerGovernanceAuditTestHost $host,
        string $status,
        string $toolName,
        ?AuthenticatedPrincipal $principal,
        ?string $target = 'id=10',
        string $method = 'tools/call'
    ): void {
        $reflection = new ReflectionMethod($host, 'recordGovernanceAudit');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $host,
            microtime(true),
            $method,
            $toolName,
            $status,
            null,
            200,
            '203.0.113.9',
            'admin',
            $principal,
            'req-123',
            $target
        );
    }

    public function testSuccessfulMutatingToolCallByAuthenticatedPrincipalRecordsAuditAndActionLog(): void
    {
        ['host' => $host, 'db' => $db, 'calls' => $calls] = $this->boot();

        $this->recordGovernanceAudit($host, 'ok', 'update_article', $this->principal(42), 'id=10');

        $this->assertTrue($db->executed, 'audit row must be persisted');
        $this->assertCount(1, $calls, 'a successful mutating tool call must produce one Action Log entry');
        $this->assertSame(42, $calls[0]['userId']);
        $this->assertSame('update_article', $calls[0]['message']['tool']);
        $this->assertSame('id=10', $calls[0]['message']['target']);
    }

    public function testSuccessfulReadOnlyToolCallRecordsAuditButNotActionLog(): void
    {
        ['host' => $host, 'db' => $db, 'calls' => $calls] = $this->boot();

        $this->recordGovernanceAudit($host, 'ok', 'get_article_by_id', $this->principal());

        $this->assertTrue($db->executed, 'audit row must still be persisted for read-only tools');
        $this->assertCount(0, $calls, 'a read-only tool call must never produce an Action Log entry');
    }

    public function testErrorOutcomeRecordsAuditButNotActionLogEvenForMutatingTool(): void
    {
        ['host' => $host, 'db' => $db, 'calls' => $calls] = $this->boot();

        $this->recordGovernanceAudit($host, 'error', 'update_article', $this->principal());

        $this->assertTrue($db->executed);
        $this->assertCount(0, $calls, 'a failed mutation must never produce an Action Log entry');
    }

    public function testPolicyBlockedOutcomeRecordsAuditButNotActionLog(): void
    {
        ['host' => $host, 'db' => $db, 'calls' => $calls] = $this->boot();

        $this->recordGovernanceAudit($host, 'blocked', 'update_article', $this->principal());

        $this->assertTrue($db->executed);
        $this->assertCount(0, $calls, 'a policy-blocked mutation must never produce an Action Log entry');
    }

    public function testLegacyNullPrincipalStillAuditsSafelyWithoutActionLog(): void
    {
        ['host' => $host, 'db' => $db, 'calls' => $calls] = $this->boot();

        $this->recordGovernanceAudit($host, 'ok', 'update_article', null);

        $this->assertTrue($db->executed, 'legacy shared-token requests must still be audited');
        $this->assertNotNull($db->lastQuery);

        $values = explode(',', $db->lastQuery->insertValues);
        $columns = array_map(static fn (string $c): string => trim($c, '`'), $db->lastQuery->insertColumns);
        $byColumn = array_combine($columns, $values);

        $this->assertSame('NULL', $byColumn['credential_id']);
        $this->assertSame('NULL', $byColumn['user_id']);
        $this->assertCount(0, $calls, 'there is no Joomla user to attribute an Action Log entry to');
    }

    public function testAuditServiceFailureIsSwallowedAndActionLogStillFires(): void
    {
        $db = new RpcAuditFakeDatabase();
        $db->throwOnExecute = true;
        $audit = new GovernanceAuditService($db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-08-20 12:00:00'));

        $calls = [];
        $writer = function (int $userId, string $messageKey, string $context, array $message) use (&$calls): void {
            $calls[] = ['userId' => $userId, 'message' => $message];
        };
        $actionLog = new JoomlaActionLogService($writer);

        $container = new RpcHandlerGovernanceAuditTestContainer([
            GovernanceAuditService::class => $audit,
            JoomlaActionLogService::class => $actionLog,
        ]);
        (new McpserverComponent())->boot($container);

        $host = new RpcHandlerGovernanceAuditTestHost();

        $this->recordGovernanceAudit($host, 'ok', 'update_article', $this->principal());

        $this->assertCount(1, $calls, 'a failed audit write must not prevent the independent Action Log write');
    }

    public function testActionLogWriterFailureDoesNotPropagate(): void
    {
        $host = $this->boot(static function (): void {
            throw new \RuntimeException('action log table unavailable');
        })['host'];

        $this->recordGovernanceAudit($host, 'ok', 'update_article', $this->principal());

        $this->assertTrue(true, 'recordGovernanceAudit must not throw when the Action Log writer fails');
    }

    public function testExtractMutationTargetOnlyIncludesAllowlistedIdentifierKeys(): void
    {
        $host = new RpcHandlerGovernanceAuditTestHost();
        $reflection = new ReflectionMethod($host, 'extractMutationTarget');
        $reflection->setAccessible(true);

        $target = $reflection->invoke($host, [
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_article',
                'arguments' => [
                    'id' => 10,
                    'catid' => 4,
                    'article' => [
                        'title' => 'Secret launch plans',
                        'introtext' => '<p>Confidential content</p>',
                    ],
                ],
            ],
        ]);

        $this->assertSame('id=10;catid=4', $target);
        $this->assertStringNotContainsString('Secret', (string) $target);
        $this->assertStringNotContainsString('Confidential', (string) $target);
    }

    public function testExtractMutationTargetReturnsNullForNonToolCallMethods(): void
    {
        $host = new RpcHandlerGovernanceAuditTestHost();
        $reflection = new ReflectionMethod($host, 'extractMutationTarget');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($host, ['method' => 'tools/list', 'params' => ['arguments' => ['id' => 1]]]));
    }
}
