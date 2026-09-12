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
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class FakeAuditQuery implements QueryInterface
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

    public function __toString(): string
    {
        return "INSERT INTO {$this->insertTable} ("
            . implode(',', $this->insertColumns)
            . ') VALUES (' . $this->insertValues . ')';
    }
}

final class FakeAuditDatabase implements DatabaseInterface
{
    public ?FakeAuditQuery $lastQuery = null;
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
        return new FakeAuditQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->lastQuery = $query instanceof FakeAuditQuery ? $query : null;

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
}

final class GovernanceAuditServiceTest extends TestCase
{
    private const FIXED_TIME = '2026-08-20 12:00:00';

    private function clock(): callable
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable(self::FIXED_TIME, new DateTimeZone('UTC'));
    }

    public function testRecordAttributesRowToAuthenticatedCredentialAndUser(): void
    {
        $db = new FakeAuditDatabase();
        $service = new GovernanceAuditService($db, $this->clock());

        $principal = new AuthenticatedPrincipal(
            credentialId: 7,
            selector: 'sel-abc',
            userId: 42,
            credentialName: 'CI Token',
            joomlaApiToken: 'super-secret-token-value',
        );

        $service->record(
            method: 'tools/call',
            toolName: 'get_articles',
            status: 'ok',
            errorCode: null,
            httpStatus: 200,
            durationMs: 15,
            clientIp: '203.0.113.9',
            context: 'admin',
            principal: $principal,
            requestId: 'req-123',
            target: 'com_content.article.10',
        );

        $this->assertTrue($db->executed);
        $this->assertNotNull($db->lastQuery);
        $this->assertSame('`#__mcpserver_request_log`', $db->lastQuery->insertTable);

        $sql = (string) $db->lastQuery;
        $this->assertStringContainsString('7', $sql);
        $this->assertStringContainsString('42', $sql);
        $this->assertStringContainsString("'sel-abc'", $sql);
        $this->assertStringContainsString("'com_content.article.10'", $sql);
        $this->assertStringNotContainsString('super-secret-token-value', $sql);
    }

    public function testRecordUsesNullAttributionForLegacySharedTokenRequest(): void
    {
        $db = new FakeAuditDatabase();
        $service = new GovernanceAuditService($db, $this->clock());

        $service->record(
            method: 'tools/call',
            toolName: 'get_articles',
            status: 'ok',
            errorCode: null,
            httpStatus: 200,
            durationMs: 10,
            clientIp: '203.0.113.9',
            context: 'site',
            principal: null,
        );

        $this->assertNotNull($db->lastQuery);
        $sql = implode(',', $db->lastQuery->insertColumns) . '|' . $db->lastQuery->insertValues;

        $values = explode(',', $db->lastQuery->insertValues);
        $columns = array_map(
            static fn (string $c): string => trim($c, '`'),
            $db->lastQuery->insertColumns
        );

        $byColumn = array_combine($columns, $values);

        $this->assertSame('NULL', $byColumn['credential_id']);
        $this->assertSame('NULL', $byColumn['user_id']);
        $this->assertSame('NULL', $byColumn['credential_selector']);
    }

    public function testRecordUsesNullAttributionForAuthFailure(): void
    {
        $db = new FakeAuditDatabase();
        $service = new GovernanceAuditService($db, $this->clock());

        $service->record(
            method: 'tools/call',
            toolName: null,
            status: 'auth_failed',
            errorCode: -32001,
            httpStatus: 401,
            durationMs: 2,
            clientIp: '203.0.113.9',
            context: 'admin',
            principal: null,
        );

        $this->assertNotNull($db->lastQuery);
        $values = explode(',', $db->lastQuery->insertValues);
        $columns = array_map(
            static fn (string $c): string => trim($c, '`'),
            $db->lastQuery->insertColumns
        );
        $byColumn = array_combine($columns, $values);

        $this->assertSame('NULL', $byColumn['credential_id']);
        $this->assertSame('NULL', $byColumn['user_id']);
        $this->assertSame('NULL', $byColumn['credential_selector']);
        $this->assertSame("'auth_failed'", $byColumn['status']);
    }

    public function testRecordRedactsControlCharactersAndTruncatesTargetTo255Bytes(): void
    {
        $db = new FakeAuditDatabase();
        $service = new GovernanceAuditService($db, $this->clock());

        $dirtyTarget = "com_content\x00\x07.article." . str_repeat('9', 300);

        $service->record(
            method: 'tools/call',
            toolName: 'get_articles',
            status: 'ok',
            errorCode: null,
            httpStatus: 200,
            durationMs: 5,
            clientIp: '203.0.113.9',
            context: 'admin',
            target: $dirtyTarget,
        );

        $this->assertNotNull($db->lastQuery);
        $values = explode(',', $db->lastQuery->insertValues);
        $columns = array_map(
            static fn (string $c): string => trim($c, '`'),
            $db->lastQuery->insertColumns
        );
        $byColumn = array_combine($columns, $values);

        $storedTarget = trim($byColumn['target'], "'");

        $this->assertStringNotContainsString("\x00", $storedTarget);
        $this->assertStringNotContainsString("\x07", $storedTarget);
        $this->assertLessThanOrEqual(255, strlen($storedTarget));
    }

    public function testRecordNeverPersistsTokenOrSecretValuesEvenWhenPresentInPrincipal(): void
    {
        $db = new FakeAuditDatabase();
        $service = new GovernanceAuditService($db, $this->clock());

        $principal = new AuthenticatedPrincipal(
            credentialId: 3,
            selector: 'sel-xyz',
            userId: 1,
            credentialName: 'Admin Token',
            joomlaApiToken: 'top-secret-joomla-api-token',
        );

        $service->record(
            method: 'tools/call',
            toolName: 'get_articles',
            status: 'ok',
            errorCode: null,
            httpStatus: 200,
            durationMs: 5,
            clientIp: '203.0.113.9',
            context: 'admin',
            principal: $principal,
        );

        $this->assertNotNull($db->lastQuery);

        $columns = array_map(
            static fn (string $c): string => trim($c, '`'),
            $db->lastQuery->insertColumns
        );

        foreach (['token', 'secret', 'bearer', 'joomla_api_token'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $sql = (string) $db->lastQuery;
        $this->assertStringNotContainsString('top-secret-joomla-api-token', $sql);
    }
}
