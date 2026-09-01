<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditQueryService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeAuditQueryQuery implements QueryInterface
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
    /** @var list<array{type:string,table:string,condition:string}> */
    public array $joins = [];

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

    public function join(string $type, string $table, string $condition = ''): self
    {
        $this->joins[] = ['type' => $type, 'table' => $table, 'condition' => $condition];

        return $this;
    }

    public function __toString(): string
    {
        return 'SELECT ' . implode(',', $this->selectColumns)
            . ' FROM ' . (string) $this->fromTable
            . (($this->whereConditions !== []) ? ' WHERE ' . implode(' AND ', $this->whereConditions) : '')
            . (($this->orderClause !== '') ? ' ORDER BY ' . $this->orderClause : '');
    }
}

final class FakeAuditQueryDatabase implements DatabaseInterface
{
    public ?FakeAuditQueryQuery $lastQuery = null;
    public int $lastOffset = 0;
    public int $lastLimit = 0;
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        if (is_array($name)) {
            return array_map(static fn (string $n): string => '`' . $n . '`', $name);
        }

        $quoted = '`' . $name . '`';
        if (is_string($alias) && $alias !== '') {
            $quoted .= ' AS `' . $alias . '`';
        }

        return $quoted;
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
        return new FakeAuditQueryQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery  = $query instanceof FakeAuditQueryQuery ? $query : null;
        $this->lastOffset = $offset;
        $this->lastLimit  = $limit;

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
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadAssocList(): array
    {
        return $this->rows;
    }
}

final class GovernanceAuditQueryServiceTest extends TestCase
{
    public function testSearchSelectsOnlySafeColumnsExcludingSecrets(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search();

        $this->assertNotNull($db->lastQuery);
        $columns = array_map(
            static fn (string $c): string => trim($c, '`'),
            $db->lastQuery->selectColumns
        );

        foreach (['token', 'secret', 'bearer', 'body', 'content', 'joomla_api_token', 'verifier', 'token_ciphertext'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $this->assertContains('audit.method', $columns);
        $this->assertContains('audit.status', $columns);
        $this->assertContains('audit.user_id', $columns);
        $this->assertContains('audit.target', $columns);
        $this->assertContains('users.name` AS `user_name', $columns);
    }

    public function testSearchQueriesOnlyRequestLogTable(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search();

        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_request_log` AS `audit`', $db->lastQuery->fromTable);
        $this->assertSame('LEFT', $db->lastQuery->joins[0]['type']);
        $this->assertSame('`#__users` AS `users`', $db->lastQuery->joins[0]['table']);
        $this->assertSame('`users.id` = `audit.user_id`', $db->lastQuery->joins[0]['condition']);
    }

    public function testSearchOrdersByMostRecentFirst(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search();

        $this->assertNotNull($db->lastQuery);
        $this->assertStringContainsString('DESC', $db->lastQuery->orderClause);
        $this->assertStringContainsString('`audit.id`', $db->lastQuery->orderClause);
    }

    public function testSearchAppliesUserIdFilterWhenProvided(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search(['userId' => 42]);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;
        $this->assertStringContainsString('`audit.user_id` = 42', $sql);
    }

    public function testSearchOmitsUserIdFilterWhenNull(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search(['userId' => null]);

        $this->assertNotNull($db->lastQuery);
        $this->assertSame([], $db->lastQuery->whereConditions);
    }

    public function testSearchAppliesToolNameFilterWhenProvided(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search(['toolName' => 'get_articles']);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;
        $this->assertStringContainsString("`audit.tool_name` = 'get_articles'", $sql);
    }

    public function testSearchAppliesDateRangeFiltersWhenProvided(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search(['dateFrom' => '2026-08-01', 'dateTo' => '2026-08-21 10:30:00']);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;
        $this->assertStringContainsString("`audit.created` >= '2026-08-01'", $sql);
        $this->assertStringContainsString("`audit.created` <= '2026-08-21 10:30:00'", $sql);
    }

    public function testSearchExpandsDateOnlyDateToToTheEndOfTheSelectedUtcDay(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search(['dateTo' => '2026-08-21']);

        $this->assertNotNull($db->lastQuery);
        $sql = (string) $db->lastQuery;
        $this->assertStringContainsString("`audit.created` <= '2026-08-21 23:59:59'", $sql);
        $this->assertStringNotContainsString("`created` <= '2026-08-21'", $sql);
    }

    public function testSearchRejectsMalformedDateFilter(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $this->expectException(InvalidArgumentException::class);
        $service->search(['dateFrom' => 'not-a-date; DROP TABLE users']);
    }

    public function testSearchClampsLimitToConfiguredMaximum(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search([], 10000);

        $this->assertLessThanOrEqual(200, $db->lastLimit);
    }

    public function testSearchClampsLimitToAtLeastOne(): void
    {
        $db = new FakeAuditQueryDatabase();
        $service = new GovernanceAuditQueryService($db);

        $service->search([], -5);

        $this->assertGreaterThanOrEqual(1, $db->lastLimit);
    }

    public function testSearchReturnsRowsFromDatabase(): void
    {
        $db = new FakeAuditQueryDatabase();
        $db->rows = [
            ['id' => 1, 'method' => 'tools/call', 'status' => 'ok'],
        ];
        $service = new GovernanceAuditQueryService($db);

        $result = $service->search();

        $this->assertSame($db->rows, $result);
    }
}
