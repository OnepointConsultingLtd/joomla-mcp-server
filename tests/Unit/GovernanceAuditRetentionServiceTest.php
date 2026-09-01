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
use DateTimeZone;
use InvalidArgumentException;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditRetentionService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeRetentionQuery implements QueryInterface
{
    public array $selectColumns = [];
    public array|string $fromTable = '';
    /** @var list<string> */
    public array $whereConditions = [];
    public string $updateTable = '';
    /** @var list<string> */
    public array $setValues = [];
    public string $insertTable = '';
    /** @var list<string> */
    public array $insertColumns = [];
    public string $insertValues = '';
    public ?string $deleteTable = null;

    public function select(array|string $columns): self
    {
        $this->selectColumns = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    public function from(array|string $tables): self
    {
        $this->fromTable = $tables;

        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        $this->whereConditions[] = is_array($conditions) ? implode(" {$glue} ", $conditions) : $conditions;

        return $this;
    }

    public function update(string $table): self
    {
        $this->updateTable = $table;

        return $this;
    }

    public function set(array|string $values): self
    {
        $this->setValues = is_array($values) ? $values : [$values];

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

    public function delete(?string $table = null): self
    {
        $this->deleteTable = $table;

        return $this;
    }

    public function __toString(): string
    {
        return 'DELETE FROM ' . (string) $this->deleteTable
            . ' WHERE ' . implode(' AND ', $this->whereConditions);
    }
}

final class FakeRetentionDatabase implements DatabaseInterface
{
    public ?FakeRetentionQuery $lastQuery = null;
    public bool $executed = false;
    public int $affectedRows = 0;

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
        return new FakeRetentionQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query instanceof FakeRetentionQuery ? $query : null;

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
        $this->executed = true;

        return true;
    }

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }
}

final class GovernanceAuditRetentionServiceTest extends TestCase
{
    private const FIXED_TIME = '2026-08-21 12:00:00';

    private function clock(): callable
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable(self::FIXED_TIME, new DateTimeZone('UTC'));
    }

    public function testPruneDeletesOnlyRequestLogTableWithCutoffCondition(): void
    {
        $db = new FakeRetentionDatabase();
        $db->affectedRows = 5;
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $deleted = $service->prune(30);

        $this->assertTrue($db->executed);
        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_request_log`', $db->lastQuery->deleteTable);
        $this->assertSame(5, $deleted);
    }

    public function testPruneComputesStrictlyOlderUtcCutoffFromRetentionDays(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $service->prune(30);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;

        $this->assertStringContainsString('`created`', $sql);
        $this->assertStringContainsString('<', $sql);
        $this->assertStringContainsString("'2026-07-22 12:00:00'", $sql);
    }

    public function testPruneNeverReferencesCredentialOrActionLogTables(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $service->prune(90);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;

        $this->assertStringNotContainsString('credential', $sql);
        $this->assertStringNotContainsString('action_log', $sql);
        $this->assertStringNotContainsString('user_action_log', $sql);
    }

    public function testPruneAcceptsMinimumBoundaryOfOneDay(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $service->prune(1);

        $this->assertNotNull($db->lastQuery);
        $this->assertStringContainsString("'2026-08-20 12:00:00'", (string) $db->lastQuery);
    }

    public function testPruneAcceptsMaximumBoundaryOf3650Days(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $deleted = $service->prune(3650);

        $this->assertIsInt($deleted);
        $this->assertNotNull($db->lastQuery);
    }

    public function testPruneRejectsZeroRetentionDays(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $this->expectException(InvalidArgumentException::class);
        $service->prune(0);
    }

    public function testPruneRejectsNegativeRetentionDays(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $this->expectException(InvalidArgumentException::class);
        $service->prune(-1);
    }

    public function testPruneRejectsRetentionDaysAbove3650(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $this->expectException(InvalidArgumentException::class);
        $service->prune(3651);
    }

    public function testPruneDoesNotExecuteQueryWhenValidationFails(): void
    {
        $db = new FakeRetentionDatabase();
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        try {
            $service->prune(9999);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertFalse($db->executed);
        $this->assertNull($db->lastQuery);
    }

    public function testPruneReturnsDeletedRowCountFromDatabase(): void
    {
        $db = new FakeRetentionDatabase();
        $db->affectedRows = 0;
        $service = new GovernanceAuditRetentionService($db, $this->clock());

        $this->assertSame(0, $service->prune(7));

        $db2 = new FakeRetentionDatabase();
        $db2->affectedRows = 123;
        $service2 = new GovernanceAuditRetentionService($db2, $this->clock());

        $this->assertSame(123, $service2->prune(7));
    }
}
