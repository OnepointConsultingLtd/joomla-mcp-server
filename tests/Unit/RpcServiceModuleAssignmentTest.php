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
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FakeModuleQuery implements QueryInterface
{
    public string $type = '';
    public string $table = '';
    /** @var list<string> */
    public array $selectColumns = [];
    /** @var list<string> */
    public array $whereConditions = [];
    /** @var list<string> */
    public array $insertColumns = [];
    /** @var list<string> */
    public array $insertValues = [];

    public function select(array|string $columns): self
    {
        $this->type = 'select';
        $this->selectColumns = is_array($columns) ? array_values($columns) : [$columns];

        return $this;
    }

    public function from(array|string $tables): self
    {
        $this->table = is_array($tables) ? implode(', ', $tables) : $tables;

        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        $this->whereConditions[] = is_array($conditions) ? implode(" {$glue} ", $conditions) : $conditions;

        return $this;
    }

    public function delete(string $table): self
    {
        $this->type = 'delete';
        $this->table = $table;

        return $this;
    }

    public function insert(string $table): self
    {
        $this->type = 'insert';
        $this->table = $table;

        return $this;
    }

    public function columns(array|string $columns): self
    {
        $this->insertColumns = is_array($columns) ? array_values($columns) : [$columns];

        return $this;
    }

    public function values(array|string $values): self
    {
        foreach ((array) $values as $value) {
            $this->insertValues[] = $value;
        }

        return $this;
    }

    public function update(string $table): self
    {
        $this->type = 'update';
        $this->table = $table;

        return $this;
    }

    public function set(array|string $values): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return match ($this->type) {
            'delete' => "DELETE FROM {$this->table} WHERE " . implode(' AND ', $this->whereConditions),
            'insert' => "INSERT INTO {$this->table} (" . implode(', ', $this->insertColumns) . ') VALUES ('
                . implode('), (', $this->insertValues) . ')',
            default => 'SELECT ' . implode(', ', $this->selectColumns) . " FROM {$this->table} WHERE "
                . implode(' AND ', $this->whereConditions),
        };
    }
}

final class FakeModuleDatabase implements DatabaseInterface
{
    /** Statements actually run against the database, in order. @var list<string> */
    public array $executed = [];
    /** @var list<array{table: string, row: object}> */
    public array $updatedObjects = [];
    /** The #__modules row update_module loads before merging params. */
    public ?object $moduleRow = null;
    /** Menu item ids the existence check finds. @var list<int> */
    public array $existingMenuItemIds = [];
    /** @var list<string> */
    public array $queried = [];

    private ?FakeModuleQuery $query = null;

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
        return new FakeModuleQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->query = $query instanceof FakeModuleQuery ? $query : null;

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

    public function loadObject(): ?object
    {
        $this->queried[] = (string) $this->query;

        return $this->moduleRow;
    }

    /**
     * @return list<int>
     */
    public function loadColumn(): array
    {
        $this->queried[] = (string) $this->query;

        return $this->existingMenuItemIds;
    }

    public function updateObject(string $table, object $object, string $key): bool
    {
        $this->updatedObjects[] = ['table' => $table, 'row' => clone $object];

        return true;
    }

    public function execute(): bool
    {
        $this->executed[] = (string) $this->query;

        return true;
    }
}

class RpcServiceModuleAssignmentTest extends TestCase
{
    private FakeModuleDatabase $db;

    protected function setUp(): void
    {
        Factory::reset();

        $this->db = new FakeModuleDatabase();
        $this->db->moduleRow = (object) ['params' => '{"moduleclass_sfx":"-card"}'];
        Factory::$dbo = $this->db;
    }

    protected function tearDown(): void
    {
        Factory::reset();
    }

    public function testAssignmentToSelectedPagesStoresPositiveMenuIds(): void
    {
        $this->db->existingMenuItemIds = [101, 102];

        $this->callUpdateModule(['id' => 118, 'assignment' => 1, 'assigned' => [101, 102]]);

        $this->assertSame(
            [
                'DELETE FROM `#__modules_menu` WHERE `moduleid` = 118',
                'INSERT INTO `#__modules_menu` (`moduleid`, `menuid`) VALUES (118, 101), (118, 102)',
            ],
            $this->db->executed
        );
        // Assignment alone is a legitimate update: it must not be rejected as
        // "No updatable fields supplied", nor write an empty #__modules row.
        $this->assertSame([], $this->db->updatedObjects);
    }

    /**
     * Joomla reports the ids of an "all except" assignment as negative and expects
     * them stored that way, so both signs must resolve to the same rows.
     *
     * @dataProvider excludedMenuItemSigns
     */
    public function testAssignmentToAllPagesExceptStoresNegativeMenuIds(int $suppliedId): void
    {
        $this->db->existingMenuItemIds = [101];

        $this->callUpdateModule(['id' => 118, 'assignment' => -1, 'assigned' => [$suppliedId]]);

        $this->assertSame(
            [
                'DELETE FROM `#__modules_menu` WHERE `moduleid` = 118',
                'INSERT INTO `#__modules_menu` (`moduleid`, `menuid`) VALUES (118, -101)',
            ],
            $this->db->executed
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function excludedMenuItemSigns(): array
    {
        return [
            'positive id' => [101],
            'id as reported by get_module_by_id' => [-101],
        ];
    }

    public function testAssignmentToAllPagesStoresTheSingleAllPagesRow(): void
    {
        $this->callUpdateModule(['id' => 118, 'assignment' => 0]);

        $this->assertSame(
            [
                'DELETE FROM `#__modules_menu` WHERE `moduleid` = 118',
                'INSERT INTO `#__modules_menu` (`moduleid`, `menuid`) VALUES (118, 0)',
            ],
            $this->db->executed
        );
    }

    public function testAssignmentToNoPagesLeavesNoRows(): void
    {
        $this->callUpdateModule(['id' => 118, 'assignment' => '-']);

        $this->assertSame(['DELETE FROM `#__modules_menu` WHERE `moduleid` = 118'], $this->db->executed);
    }

    public function testRepeatedMenuItemsAreStoredOnce(): void
    {
        $this->db->existingMenuItemIds = [101];

        $this->callUpdateModule(['id' => 118, 'assignment' => 1, 'assigned' => [101, 101]]);

        $this->assertSame(
            'INSERT INTO `#__modules_menu` (`moduleid`, `menuid`) VALUES (118, 101)',
            $this->db->executed[1]
        );
    }

    public function testFieldsAndAssignmentAreUpdatedTogether(): void
    {
        $this->db->existingMenuItemIds = [101];

        $this->callUpdateModule([
            'id' => 118,
            'title' => 'Sidebar promo',
            'params' => ['layout' => '_:default'],
            'assignment' => 1,
            'assigned' => [101],
        ]);

        $this->assertCount(1, $this->db->updatedObjects);
        $this->assertSame('#__modules', $this->db->updatedObjects[0]['table']);
        $this->assertSame('Sidebar promo', $this->db->updatedObjects[0]['row']->title);
        // The merge contract is unchanged: params the caller did not send survive.
        $this->assertSame(
            ['moduleclass_sfx' => '-card', 'layout' => '_:default'],
            json_decode($this->db->updatedObjects[0]['row']->params, true)
        );
        $this->assertCount(2, $this->db->executed);
    }

    public function testAnUpdateWithoutAssignmentLeavesTheExistingAssignmentAlone(): void
    {
        $this->callUpdateModule(['id' => 118, 'title' => 'Sidebar promo']);

        $this->assertCount(1, $this->db->updatedObjects);
        $this->assertSame([], $this->db->executed);
    }

    public function testMenuItemsWithoutAnAssignmentModeAreRejected(): void
    {
        $error = $this->callUpdateModuleExpectingError(['id' => 118, 'assigned' => [101]]);

        $this->assertStringContainsString('assignment is required', $error);
        $this->assertSame([], $this->db->executed);
    }

    /**
     * Core turns assignment 1 with an empty list into "no pages" and assignment -1
     * with an empty list into "all pages"; neither is what the caller asked for.
     *
     * @dataProvider selectiveAssignments
     */
    public function testASelectiveAssignmentWithoutMenuItemsIsRejected(int $assignment): void
    {
        $error = $this->callUpdateModuleExpectingError([
            'id' => 118,
            'assignment' => $assignment,
            'assigned' => [],
        ]);

        $this->assertStringContainsString('at least one menu item ID', $error);
        $this->assertSame([], $this->db->executed);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function selectiveAssignments(): array
    {
        return [
            'only the selected pages' => [1],
            'all except the selected pages' => [-1],
        ];
    }

    public function testMenuItemsCombinedWithAnAllPagesAssignmentAreRejected(): void
    {
        $error = $this->callUpdateModuleExpectingError([
            'id' => 118,
            'assignment' => 0,
            'assigned' => [101],
        ]);

        $this->assertStringContainsString('assigned cannot be combined with assignment 0', $error);
        $this->assertSame([], $this->db->executed);
    }

    public function testAnUnknownMenuItemIsRejectedBeforeAnythingIsWritten(): void
    {
        $this->db->existingMenuItemIds = [101];

        $error = $this->callUpdateModuleExpectingError([
            'id' => 118,
            'title' => 'Sidebar promo',
            'assignment' => 1,
            'assigned' => [101, 999],
        ]);

        $this->assertStringContainsString('Menu item 999 does not exist as a site menu item', $error);
        // The field update must not land either — a rejected call changes nothing.
        $this->assertSame([], $this->db->updatedObjects);
        $this->assertSame([], $this->db->executed);
    }

    public function testTheExistenceCheckLooksOnlyAtSiteMenuItems(): void
    {
        $this->db->existingMenuItemIds = [101];

        $this->callUpdateModule(['id' => 118, 'assignment' => 1, 'assigned' => [101]]);

        $menuQuery = end($this->db->queried);
        $this->assertStringContainsString('FROM `#__menu`', $menuQuery);
        $this->assertStringContainsString('`id` IN (101)', $menuQuery);
        $this->assertStringContainsString('`client_id` = 0', $menuQuery);
    }

    public function testAdministratorModulesRejectMenuAssignment(): void
    {
        $error = $this->callUpdateModuleExpectingError([
            'id' => 118,
            'client' => 'administrator',
            'assignment' => 0,
        ]);

        $this->assertStringContainsString('applies to site modules only', $error);
        $this->assertSame([], $this->db->executed);
    }

    public function testAnUpdateWithNeitherFieldsNorAssignmentIsStillRejected(): void
    {
        $error = $this->callUpdateModuleExpectingError(['id' => 118]);

        $this->assertStringContainsString('No updatable fields supplied', $error);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callUpdateModule(array $arguments): array
    {
        $response = $this->callTool($this->makeService(), $arguments);

        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('isError', $response['result'], json_encode($response['result']['content'] ?? []));

        return $response['result']['structuredContent'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callUpdateModuleExpectingError(array $arguments): string
    {
        $response = $this->callTool($this->makeService(), $arguments);

        $this->assertTrue($response['result']['isError'] ?? false, 'the call was expected to fail');

        return $response['result']['content'][0]['text'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(RpcService $service, array $arguments): array
    {
        return $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_module',
                'arguments' => $arguments,
            ],
        ]);
    }

    private function makeService(): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        $policy->method('resourcesEnabled')->willReturn(false);
        $policy->method('promptsEnabled')->willReturn(false);

        return new RpcService(
            $this->createRestMock(),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        $rest = $this->createMock(RestClient::class);
        // The executor re-reads the module through the Web Services API and returns
        // it, so the caller sees the assignment Joomla now reports.
        $rest->method('get')->willReturn(['data' => ['id' => 118]]);

        return $rest;
    }
}
