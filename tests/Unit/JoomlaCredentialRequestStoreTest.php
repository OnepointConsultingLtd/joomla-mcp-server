<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialRequestStore;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeRequestQuery implements QueryInterface
{
    public array $selects = [];
    public array $froms = [];
    public array $joins = [];
    public array $wheres = [];

    public function select(array|string $columns): self
    {
        foreach ((array) $columns as $c) {
            $this->selects[] = $c;
        }

        return $this;
    }

    public function from(array|string $tables): self
    {
        $this->froms[] = $tables;

        return $this;
    }

    public function join(string $type, string $table, string $condition = ''): self
    {
        $this->joins[] = $type . ' ' . $table . ' ON ' . $condition;

        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        $this->wheres[] = is_array($conditions) ? implode(" {$glue} ", $conditions) : $conditions;

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
        return $this;
    }

    public function columns(array|string $columns): self
    {
        return $this;
    }

    public function values(array|string $values): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return 'SELECT ' . implode(',', $this->selects)
            . ' FROM ' . implode(',', (array) $this->froms)
            . ' ' . implode(' ', $this->joins)
            . ' WHERE ' . implode(' AND ', $this->wheres);
    }
}

final class FakeRequestDatabase implements DatabaseInterface
{
    public ?FakeRequestQuery $lastQuery = null;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        if (is_array($name)) {
            return array_map(static fn (string $n): string => '`' . $n . '`', $name);
        }

        return $alias === null ? '`' . $name . '`' : '`' . $name . '` AS `' . $alias . '`';
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
        return new FakeRequestQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function loadAssocList(): array
    {
        return $this->rows;
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function execute(): bool
    {
        return true;
    }
}

/**
 * The pending-requests queue shows who is asking, not a bare user id, so the
 * listing joins #__users. These pin the join and the deleted-owner case.
 */
final class JoomlaCredentialRequestStoreTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '7',
            'user_id' => '42',
            'client_name' => 'CI runner',
            'status' => 'requested',
            'credential_expires' => null,
            'credential_id' => null,
            'username' => 'ada',
            'user_name' => 'Ada Lovelace',
        ], $overrides);
    }

    public function testListPendingReturnsTheOwnerUsernameAndDisplayName(): void
    {
        $store = new JoomlaCredentialRequestStore(new FakeRequestDatabase([$this->row()]));

        $rows = $store->listPending();

        $this->assertCount(1, $rows);
        $this->assertSame('ada', $rows[0]['username']);
        $this->assertSame('Ada Lovelace', $rows[0]['user_name']);
        $this->assertSame(42, $rows[0]['user_id'], 'the id stays available for the audit trail');
    }

    /**
     * A deleted owner must not make the request vanish from the approval queue:
     * an admin still needs to see and reject it.
     */
    public function testDeletedOwnerYieldsNullNamesRatherThanDroppingTheRow(): void
    {
        $db = new FakeRequestDatabase([$this->row(['username' => null, 'user_name' => null])]);
        $store = new JoomlaCredentialRequestStore($db);

        $rows = $store->listPending();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['username']);
        $this->assertNull($rows[0]['user_name']);
        $this->assertSame(42, $rows[0]['user_id']);
    }

    public function testEmptyUsernameIsNormalisedToNull(): void
    {
        $db = new FakeRequestDatabase([$this->row(['username' => '', 'user_name' => ''])]);

        $rows = (new JoomlaCredentialRequestStore($db))->listPending();

        $this->assertNull($rows[0]['username']);
        $this->assertNull($rows[0]['user_name']);
    }

    public function testListingLeftJoinsUsersSoRowsAreNeverDropped(): void
    {
        $db = new FakeRequestDatabase([$this->row()]);

        (new JoomlaCredentialRequestStore($db))->listPending();

        $this->assertNotNull($db->lastQuery);
        $joins = implode(' ', $db->lastQuery->joins);
        $this->assertStringContainsString('LEFT', $joins, 'an INNER join would hide requests from deleted users');
        $this->assertStringContainsString('#__users', $joins);
    }

    /**
     * `id` exists in both tables, so every condition has to be qualified or the
     * join makes the query ambiguous.
     */
    public function testConditionsAreQualifiedWithTheRequestTableAlias(): void
    {
        $db = new FakeRequestDatabase([]);
        $store = new JoomlaCredentialRequestStore($db);

        $store->listPending();
        $this->assertStringContainsString('`r.status`', implode(' ', $db->lastQuery->wheres));

        $store->listForUser(42);
        $this->assertStringContainsString('`r.user_id`', implode(' ', $db->lastQuery->wheres));
    }

    public function testFindStillWorksWithoutTheJoinedColumns(): void
    {
        // find() does not join, so metadata() must tolerate absent name columns.
        $row = $this->row();
        unset($row['username'], $row['user_name']);
        $db = new FakeRequestDatabase([$row]);

        $found = (new JoomlaCredentialRequestStore($db))->find('7');

        $this->assertNotNull($found);
        $this->assertNull($found['username']);
        $this->assertSame('CI runner', $found['client_name']);
    }
}
