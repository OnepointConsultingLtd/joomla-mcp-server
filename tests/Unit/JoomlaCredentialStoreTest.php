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
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialStore;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeCredentialQuery implements QueryInterface
{
    public array $selectColumns = [];
    public array|string $fromTable = '';
    /** @var list<string> */
    public array $whereConditions = [];
    public string $updateTable = '';
    /** @var list<string> */
    public array $setValues = [];

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

    public function insert(string $table): self { return $this; }
    public function columns(array|string $columns): self { return $this; }
    public function values(array|string $values): self { return $this; }

    public function set(array|string $values): self
    {
        $this->setValues = is_array($values) ? $values : [$values];

        return $this;
    }

    public function __toString(): string
    {
        return $this->updateTable !== ''
            ? "UPDATE {$this->updateTable} SET " . implode(', ', $this->setValues) . ' WHERE ' . implode(' AND ', $this->whereConditions)
            : 'SELECT ' . implode(', ', $this->selectColumns) . " FROM {$this->fromTable} WHERE " . implode(' AND ', $this->whereConditions);
    }
}

final class FakeCredentialDatabase implements DatabaseInterface
{
    public ?FakeCredentialQuery $lastQuery = null;
    public ?array $assocRow = null;
    public bool $executed = false;

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
        return new FakeCredentialQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query instanceof FakeCredentialQuery ? $query : null;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->assocRow;
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
}

final class JoomlaCredentialStoreTest extends TestCase
{
    public function testFindBySelectorMapsRowAndFiltersBySelector(): void
    {
        $db = new FakeCredentialDatabase();
        $db->assocRow = [
            'id' => '7',
            'selector' => 'sel-abc',
            'user_id' => '42',
            'name' => 'CI Token',
            'verifier' => 'hashed-verifier',
            'token_ciphertext' => 'cipher-b64',
            'token_nonce' => 'nonce-b64',
            'token_tag' => 'tag-b64',
            'key_version' => '1',
            'status' => 'active',
            'expires' => '2026-09-01 12:00:00',
            'revoked' => null,
        ];

        $store = new JoomlaCredentialStore($db);
        $record = $store->findBySelector('sel-abc');

        $this->assertNotNull($record);
        $this->assertSame(7, $record->id);
        $this->assertSame('sel-abc', $record->selector);
        $this->assertSame(42, $record->userId);
        $this->assertSame('CI Token', $record->name);
        $this->assertSame('hashed-verifier', $record->verifier);
        $this->assertSame(
            ['ciphertext' => 'cipher-b64', 'nonce' => 'nonce-b64', 'tag' => 'tag-b64', 'key_version' => 1],
            $record->encryptedToken
        );
        $this->assertSame('active', $record->status);
        $this->assertEquals(new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')), $record->expires);
        $this->assertNull($record->revoked);

        $this->assertNotNull($db->lastQuery);
        $this->assertStringContainsString("`selector` = 'sel-abc'", implode(' AND ', $db->lastQuery->whereConditions));
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->fromTable);
    }

    public function testFindBySelectorReturnsNullWhenNoRow(): void
    {
        $db = new FakeCredentialDatabase();
        $db->assocRow = null;

        $store = new JoomlaCredentialStore($db);

        $this->assertNull($store->findBySelector('missing-selector'));
    }

    public function testTouchLastUsedIssuesParameterizedUpdateWithCurrentUtcTime(): void
    {
        $db = new FakeCredentialDatabase();
        $store = new JoomlaCredentialStore($db);

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $store->touchLastUsed(9);
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->assertTrue($db->executed);
        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_credential`', $db->lastQuery->updateTable);
        $this->assertStringContainsString('`id` = 9', implode(' AND ', $db->lastQuery->whereConditions));

        $setValue = $db->lastQuery->setValues[0];
        $this->assertMatchesRegularExpression('/^`last_used` = \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'$/', $setValue);
        preg_match('/\'(.+)\'/', $setValue, $matches);
        $touched = new DateTimeImmutable($matches[1], new DateTimeZone('UTC'));

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $touched->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $touched->getTimestamp());
    }
}
