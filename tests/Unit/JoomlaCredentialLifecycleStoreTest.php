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
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialLifecycleStore;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeLifecycleQuery implements QueryInterface
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

    public function set(array|string $values): self
    {
        $this->setValues = is_array($values) ? $values : [$values];

        return $this;
    }

    public function delete(string $table): self
    {
        $this->deleteTable = $table;

        return $this;
    }

    public function __toString(): string
    {
        if ($this->insertTable !== '') {
            return "INSERT INTO {$this->insertTable} (" . implode(',', $this->insertColumns) . ') VALUES (' . $this->insertValues . ')';
        }

        if ($this->deleteTable !== '') {
            return "DELETE FROM {$this->deleteTable} WHERE " . implode(' AND ', $this->whereConditions);
        }

        return $this->updateTable !== ''
            ? "UPDATE {$this->updateTable} SET " . implode(', ', $this->setValues) . ' WHERE ' . implode(' AND ', $this->whereConditions)
            : 'SELECT ' . implode(', ', $this->selectColumns) . " FROM {$this->fromTable} WHERE " . implode(' AND ', $this->whereConditions);
    }
}

final class FakeLifecycleDatabase implements DatabaseInterface
{
    /** @var list<FakeLifecycleQuery> */
    public array $queries = [];
    public ?FakeLifecycleQuery $lastQuery = null;
    public ?array $assocRow = null;
    /** @var list<array<string,mixed>> */
    public array $assocList = [];
    public bool $executed = false;
    public int $nextInsertId = 0;
    public int $executeCount = 0;
    public ?int $throwOnExecuteCall = null;
    public bool $transactionStarted = false;
    public bool $transactionCommitted = false;
    public bool $transactionRolledBack = false;

    public function transactionStart(): void
    {
        $this->transactionStarted = true;
    }

    public function transactionCommit(): void
    {
        $this->transactionCommitted = true;
    }

    public function transactionRollback(): void
    {
        $this->transactionRolledBack = true;
    }

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
        return new FakeLifecycleQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query instanceof FakeLifecycleQuery ? $query : null;
        if ($this->lastQuery !== null) {
            $this->queries[] = $this->lastQuery;
        }

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->assocRow;
    }

    public function loadAssocList(): array
    {
        return $this->assocList;
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function execute(): bool
    {
        $this->executeCount++;
        if ($this->throwOnExecuteCall === $this->executeCount) {
            throw new \RuntimeException('simulated database failure');
        }

        $this->executed = true;

        return true;
    }

    public function insertid(): int
    {
        return $this->nextInsertId;
    }
}

final class JoomlaCredentialLifecycleStoreTest extends TestCase
{
    public function testSaveInsertsAllSchemaFieldsWithUtcTimestampsAndReturnsInsertId(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->nextInsertId = 15;
        $store = new JoomlaCredentialLifecycleStore($db);

        $id = $store->save([
            'owner_id' => 42,
            'owner_name' => 'CI Bot',
            'selector' => 'sel-abc',
            'verifier' => 'hashed-verifier',
            'encrypted_token' => [
                'ciphertext' => 'cipher-b64',
                'nonce' => 'nonce-b64',
                'tag' => 'tag-b64',
                'key_version' => 1,
            ],
            'expires_at' => 1_800_000_000,
            'created_at' => 1_790_000_000,
        ]);

        $this->assertSame('15', $id);
        $this->assertTrue($db->executed);
        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->insertTable);
        $this->assertSame(
            ['`selector`', '`user_id`', '`name`', '`verifier`', '`token_ciphertext`', '`token_nonce`', '`token_tag`', '`key_version`', '`status`', '`created`', '`expires`'],
            $db->lastQuery->insertColumns
        );

        $values = $db->lastQuery->insertValues;
        $this->assertStringContainsString("'sel-abc'", $values);
        $this->assertStringContainsString('42', $values);
        $this->assertStringContainsString("'CI Bot'", $values);
        $this->assertStringContainsString("'hashed-verifier'", $values);
        $this->assertStringContainsString("'cipher-b64'", $values);
        $this->assertStringContainsString("'nonce-b64'", $values);
        $this->assertStringContainsString("'tag-b64'", $values);
        $this->assertStringContainsString("'active'", $values);
        $this->assertStringNotContainsString('bearer', $values);

        $expectedCreated = (new DateTimeImmutable('@1790000000'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $expectedExpires = (new DateTimeImmutable('@1800000000'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->assertStringContainsString("'{$expectedCreated}'", $values);
        $this->assertStringContainsString("'{$expectedExpires}'", $values);
    }

    public function testListByOwnerReturnsOnlyMetadataFilteredByOwnerExcludingSecrets(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->assocList = [
            [
                'id' => '3',
                'user_id' => '42',
                'name' => 'CI Bot',
                'selector' => 'sel-abc',
                'expires' => '2026-09-01 12:00:00',
                'created' => '2026-08-01 12:00:00',
                'status' => 'active',
            ],
            [
                'id' => '4',
                'user_id' => '42',
                'name' => 'Nightly',
                'selector' => 'sel-def',
                'expires' => '2026-09-05 12:00:00',
                'created' => '2026-08-05 12:00:00',
                'status' => 'revoked',
            ],
        ];

        $store = new JoomlaCredentialLifecycleStore($db);
        $rows = $store->listByOwner(42);

        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->fromTable);
        $this->assertStringContainsString('`user_id` = 42', implode(' AND ', $db->lastQuery->whereConditions));
        $this->assertNotContains('`verifier`', $db->lastQuery->selectColumns);
        $this->assertNotContains('`token_ciphertext`', $db->lastQuery->selectColumns);

        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'owner_id', 'owner_name', 'selector', 'expires_at', 'created_at', 'revoked'], array_keys($rows[0]));
        $this->assertFalse($rows[0]['revoked']);
        $this->assertTrue($rows[1]['revoked']);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('verifier', $row);
            $this->assertArrayNotHasKey('encrypted_token', $row);
        }
    }

    public function testFindOwnershipReturnsNullForNonNumericId(): void
    {
        $db = new FakeLifecycleDatabase();
        $store = new JoomlaCredentialLifecycleStore($db);

        $this->assertNull($store->findOwnership('not-numeric; DROP TABLE'));
        $this->assertNull($db->lastQuery);
    }

    public function testFindOwnershipReturnsOwnerAndRevokedState(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->assocRow = ['id' => '9', 'user_id' => '42', 'status' => 'revoked'];
        $store = new JoomlaCredentialLifecycleStore($db);

        $record = $store->findOwnership('9');

        $this->assertSame(['id' => '9', 'owner_id' => 42, 'revoked' => true], $record);
        $this->assertNotNull($db->lastQuery);
        $this->assertStringContainsString('`id` = 9', implode(' AND ', $db->lastQuery->whereConditions));
    }

    public function testFindOwnershipReturnsNullWhenNoRow(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->assocRow = null;
        $store = new JoomlaCredentialLifecycleStore($db);

        $this->assertNull($store->findOwnership('123'));
    }

    public function testRevokeSetsStatusRevokedAndRevokedTimestampIdempotently(): void
    {
        $db = new FakeLifecycleDatabase();
        $store = new JoomlaCredentialLifecycleStore($db);

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $store->revoke('9');
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->assertTrue($db->executed);
        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->updateTable);
        $this->assertStringContainsString('`id` = 9', implode(' AND ', $db->lastQuery->whereConditions));
        $this->assertStringContainsString("`status` != 'revoked'", implode(' AND ', $db->lastQuery->whereConditions));

        $statusSet = $db->lastQuery->setValues[0];
        $revokedSet = $db->lastQuery->setValues[1];
        $this->assertSame("`status` = 'revoked'", $statusSet);
        $this->assertMatchesRegularExpression('/^`revoked` = \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'$/', $revokedSet);

        preg_match('/\'(.+)\'/', $revokedSet, $matches);
        $revokedAt = new DateTimeImmutable($matches[1], new DateTimeZone('UTC'));
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $revokedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $revokedAt->getTimestamp());
    }

    public function testDeleteRevokedDeletesOnlyMatchingRevokedCredential(): void
    {
        $db = new FakeLifecycleDatabase();
        $store = new JoomlaCredentialLifecycleStore($db);

        $store->deleteRevoked('9');

        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->deleteTable);
        $conditions = implode(' AND ', $db->lastQuery->whereConditions);
        $this->assertStringContainsString('`id` = 9', $conditions);
        $this->assertStringContainsString("`status` = 'revoked'", $conditions);
    }

    public function testDeleteRevokedRejectsNonNumericId(): void
    {
        $store = new JoomlaCredentialLifecycleStore(new FakeLifecycleDatabase());

        $this->expectException(\InvalidArgumentException::class);
        $store->deleteRevoked('9 OR 1=1');
    }

    public function testRevokeIsNoOpForNonNumericId(): void
    {
        $db = new FakeLifecycleDatabase();
        $store = new JoomlaCredentialLifecycleStore($db);

        $store->revoke('abc');

        $this->assertFalse($db->executed);
        $this->assertNull($db->lastQuery);
    }

    private function replaceRecord(): array
    {
        return [
            'owner_id' => 42,
            'owner_name' => 'CI Bot',
            'selector' => 'sel-new',
            'verifier' => 'hashed-verifier-new',
            'encrypted_token' => [
                'ciphertext' => 'cipher-new',
                'nonce' => 'nonce-new',
                'tag' => 'tag-new',
                'key_version' => 1,
            ],
            'expires_at' => 1_800_000_000,
            'created_at' => 1_790_000_000,
        ];
    }

    public function testReplaceInsertsReplacementAndRevokesOldCredentialWithinACommittedTransaction(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->nextInsertId = 21;
        $store = new JoomlaCredentialLifecycleStore($db);

        $newId = $store->replace($this->replaceRecord(), '9');

        $this->assertSame('21', $newId);
        $this->assertTrue($db->transactionStarted);
        $this->assertTrue($db->transactionCommitted);
        $this->assertFalse($db->transactionRolledBack);

        $this->assertCount(2, $db->queries);
        $this->assertSame('`#__mcpserver_credential`', $db->queries[0]->insertTable);
        $this->assertSame('`#__mcpserver_credential`', $db->queries[1]->updateTable);
        $this->assertStringContainsString('`id` = 9', implode(' AND ', $db->queries[1]->whereConditions));
        $this->assertSame(2, $db->executeCount);
    }

    public function testReplaceRollsBackAndPropagatesFailureWhenInsertFails(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->throwOnExecuteCall = 1;
        $store = new JoomlaCredentialLifecycleStore($db);

        $this->expectException(\RuntimeException::class);
        try {
            $store->replace($this->replaceRecord(), '9');
        } finally {
            $this->assertTrue($db->transactionStarted);
            $this->assertTrue($db->transactionRolledBack);
            $this->assertFalse($db->transactionCommitted);
            $this->assertCount(1, $db->queries);
        }
    }

    public function testReplaceRollsBackAndPropagatesFailureWhenRevokeFails(): void
    {
        $db = new FakeLifecycleDatabase();
        $db->throwOnExecuteCall = 2;
        $store = new JoomlaCredentialLifecycleStore($db);

        $this->expectException(\RuntimeException::class);
        try {
            $store->replace($this->replaceRecord(), '9');
        } finally {
            $this->assertTrue($db->transactionStarted);
            $this->assertTrue($db->transactionRolledBack);
            $this->assertFalse($db->transactionCommitted);
            $this->assertCount(2, $db->queries);
        }
    }
}
