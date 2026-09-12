<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\MetricsService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

final class FakeMetricsQuery implements QueryInterface
{
    /** @var list<string> */
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
    public string $orderClause = '';
    public string $groupClause = '';
    public string $deleteTable = '';

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

    public function order(array|string $columns): self
    {
        $this->orderClause = is_array($columns) ? implode(',', $columns) : $columns;

        return $this;
    }

    public function group(array|string $columns): self
    {
        $this->groupClause = is_array($columns) ? implode(',', $columns) : $columns;

        return $this;
    }

    public function delete(string $table = ''): self
    {
        $this->deleteTable = $table;

        return $this;
    }

    public function __toString(): string
    {
        return ($this->deleteTable !== '' ? 'DELETE FROM ' . $this->deleteTable : 'SELECT ' . implode(',', $this->selectColumns) . ' FROM ' . (string) $this->fromTable)
            . (($this->whereConditions !== []) ? ' WHERE ' . implode(' AND ', $this->whereConditions) : '')
            . (($this->groupClause !== '') ? ' GROUP BY ' . $this->groupClause : '')
            . (($this->orderClause !== '') ? ' ORDER BY ' . $this->orderClause : '');
    }
}

final class FakeMetricsDatabase implements DatabaseInterface
{
    /** @var list<FakeMetricsQuery> */
    public array $queries = [];
    public ?FakeMetricsQuery $lastQuery = null;
    /** @var array<string, mixed>|null */
    public ?array $assocRow = null;
    /** @var list<array<string, mixed>> */
    public array $assocRows = [];
    /** @var list<object> */
    public array $objectRows = [];

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
        return new FakeMetricsQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        if ($query instanceof FakeMetricsQuery) {
            $this->queries[] = $query;
            $this->lastQuery = $query;
        }

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->assocRow;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAssocList(): array
    {
        return $this->assocRows;
    }

    /**
     * @return list<object>
     */
    public function loadObjectList(): array
    {
        return $this->objectRows;
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function execute(): bool
    {
        return true;
    }

    public function getAffectedRows(): int
    {
        return 0;
    }
}

/**
 * The dashboard shows a non-Super-User only the request-log rows attributed
 * to their own account, so every MetricsService read has to honour the user
 * scope — the summary cards and charts just as much as the Recent Requests
 * table, since aggregates over the whole log would leak other users' activity
 * too.
 */
final class MetricsServiceUserScopeTest extends TestCase
{
    private FakeMetricsDatabase $db;

    protected function setUp(): void
    {
        $this->db = new FakeMetricsDatabase();
        Factory::$dbo = $this->db;
    }

    protected function tearDown(): void
    {
        Factory::reset();
    }

    private function service(): MetricsService
    {
        return new MetricsService(new Registry(['metrics_enabled' => 1]));
    }

    private function lastSql(): string
    {
        $this->assertNotNull($this->db->lastQuery, 'The read did not reach the database.');

        return (string) $this->db->lastQuery;
    }

    public function testGetRecentRequestsIsRestrictedToTheScopedUser(): void
    {
        $this->service()->withUserScope(7)->getRecentRequests();

        $this->assertStringContainsString('`user_id` = 7', $this->lastSql());
    }

    public function testGetSummaryIsRestrictedToTheScopedUser(): void
    {
        $this->service()->withUserScope(7)->getSummary();

        $this->assertStringContainsString('`user_id` = 7', $this->lastSql());
    }

    public function testGetTopToolsIsRestrictedToTheScopedUser(): void
    {
        $this->service()->withUserScope(7)->getTopTools();

        $this->assertStringContainsString('`user_id` = 7', $this->lastSql());
    }

    public function testGetTopMethodsIsRestrictedToTheScopedUser(): void
    {
        $this->service()->withUserScope(7)->getTopMethods();

        $this->assertStringContainsString('`user_id` = 7', $this->lastSql());
    }

    public function testGetRequestsPerDayIsRestrictedToTheScopedUser(): void
    {
        $this->service()->withUserScope(7)->getRequestsPerDay();

        $this->assertStringContainsString('`user_id` = 7', $this->lastSql());
    }

    /**
     * A user id of 0 is what the view falls back to when it cannot resolve an
     * identity. It must still be treated as a restriction that matches
     * nothing, not as "unscoped".
     */
    public function testUserScopeOfZeroStillRestrictsTheRead(): void
    {
        $this->service()->withUserScope(0)->getRecentRequests();

        $this->assertStringContainsString('`user_id` = 0', $this->lastSql());
    }

    public function testUnscopedServiceReadsTheWholeLog(): void
    {
        $this->service()->getRecentRequests();

        $this->assertStringNotContainsString('`user_id`', $this->lastSql());
    }

    public function testExplicitNullScopeReadsTheWholeLog(): void
    {
        $this->service()->withUserScope(null)->getRecentRequests();

        $this->assertStringNotContainsString('`user_id`', $this->lastSql());
    }

    /**
     * The DI container shares one MetricsService, so scoping it for one
     * viewer must not carry over to the next request that resolves it.
     */
    public function testWithUserScopeLeavesTheOriginalServiceUnscoped(): void
    {
        $service = $this->service();
        $service->withUserScope(7);

        $service->getRecentRequests();

        $this->assertStringNotContainsString('`user_id`', $this->lastSql());
    }

    /**
     * Retention is a property of the log, not of the viewer: a scoped
     * dashboard visit must still trim everyone's expired rows.
     */
    public function testPruneIgnoresTheUserScope(): void
    {
        $this->service()->withUserScope(7)->prune();

        $sql = $this->lastSql();
        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringNotContainsString('`user_id`', $sql);
    }
}
