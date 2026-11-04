<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\Component\Mcpserver\Tests\Stubs\StubDatabase;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RpcServiceTemplateUpdatedFilesTest extends TestCase
{
    private const EXTENSION_ID = 506;
    private const ARTICLE_OVERRIDE = 'html/com_content/article/default.php';
    private const MENU_OVERRIDE = 'html/mod_menu/default.php';

    private string $templateRoot;

    private StubDatabase $db;

    /** Arguments the fake TemplateModel::getCoreFile() was called with. @var list<array{string,int}> */
    private array $coreFileLookups = [];

    protected function setUp(): void
    {
        $this->templateRoot = JPATH_ROOT . '/templates/cassiopeia';

        $this->makeDir($this->templateRoot . '/html/com_content/article');
        file_put_contents($this->templateRoot . '/' . self::ARTICLE_OVERRIDE, "<?php\necho 'core';\necho 'mine';\n");

        $this->makeDir(JPATH_ROOT . '/components/com_content/tmpl/article');
        file_put_contents(JPATH_ROOT . '/components/com_content/tmpl/article/default.php', "<?php\necho 'core';\n");

        ComponentHelper::$params = new Registry([]);
    }

    protected function tearDown(): void
    {
        $this->removeTree(JPATH_ROOT);
        Factory::reset();
        ComponentHelper::reset();
    }

    public function testListReturnsRecordsWhoseOverrideStillExists(): void
    {
        $result = $this->toolResult($this->callTool('list_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
        ], [[
            $this->record(self::ARTICLE_OVERRIDE, 0, 'Joomla Update'),
            // The override was deleted after the record was written: Joomla hides it.
            $this->record(self::MENU_OVERRIDE, 1, 'Extension Update'),
            // A tampered hash_id must not be followed outside the template.
            $this->record('../../configuration.php', 0, 'Joomla Update'),
        ]]));

        $this->assertSame('cassiopeia', $result['template']);
        $this->assertSame('site', $result['client']);
        $this->assertSame([[
            'path' => self::ARTICLE_OVERRIDE,
            'checked' => false,
            'action' => 'Joomla Update',
            'created_date' => '2026-01-01 10:00:00',
            'modified_date' => '2026-02-01 10:00:00',
        ]], $result['files']);

        $clauses = $this->db->lastQuery->clauses;
        $this->assertContains('from `#__template_overrides`', $clauses);
        $this->assertContains('where `extension_id` = ' . self::EXTENSION_ID, $clauses);
        $this->assertNotContains('where `state` = 0', $clauses);
    }

    public function testListCanBeRestrictedToUncheckedRecords(): void
    {
        $this->toolResult($this->callTool('list_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
            'unchecked_only' => true,
        ], [[]]));

        $this->assertContains('where `state` = 0', $this->db->lastQuery->clauses);
    }

    public function testMarkCheckedUpdatesTheStoredRecordsAndReportsUnknownPaths(): void
    {
        $result = $this->toolResult($this->callTool('manage_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
            'paths' => ['/' . self::ARTICLE_OVERRIDE, 'html/com_contact/contact/default.php'],
            'action' => 'mark_checked',
        ], [[$this->record(self::ARTICLE_OVERRIDE, 0, 'Joomla Update')]]));

        $this->assertSame([self::ARTICLE_OVERRIDE], $result['updated']);
        $this->assertSame(['html/com_contact/contact/default.php'], $result['not_found']);

        $this->assertCount(1, $this->db->executed);
        $this->assertSame([
            'update `#__template_overrides`',
            'set `state` = 1',
            'where `extension_id` = ' . self::EXTENSION_ID,
            "where `hash_id` IN ('" . base64_encode('/' . self::ARTICLE_OVERRIDE) . "')",
        ], $this->db->executed[0]->clauses);
    }

    public function testMarkUncheckedResetsTheState(): void
    {
        $this->toolResult($this->callTool('manage_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
            'paths' => [self::ARTICLE_OVERRIDE],
            'action' => 'mark_unchecked',
        ], [[$this->record(self::ARTICLE_OVERRIDE, 1, 'Joomla Update')]]));

        $this->assertContains('set `state` = 0', $this->db->executed[0]->clauses);
    }

    public function testRemoveRecordDeletesOnlyTheTrackingRow(): void
    {
        $this->toolResult($this->callTool('manage_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
            'paths' => [self::ARTICLE_OVERRIDE],
            'action' => 'remove_record',
        ], [[$this->record(self::ARTICLE_OVERRIDE, 1, 'Joomla Update')]]));

        $this->assertSame('delete `#__template_overrides`', $this->db->executed[0]->clauses[0]);
        $this->assertContains('where `extension_id` = ' . self::EXTENSION_ID, $this->db->executed[0]->clauses);
        $this->assertFileExists($this->templateRoot . '/' . self::ARTICLE_OVERRIDE);
    }

    public function testManageRunsNoWriteWhenNoPathHasARecord(): void
    {
        $result = $this->toolResult($this->callTool('manage_template_updated_files', [
            'extension_id' => self::EXTENSION_ID,
            'paths' => [self::MENU_OVERRIDE],
            'action' => 'remove_record',
        ], [[]]));

        $this->assertSame([], $result['updated']);
        $this->assertSame([self::MENU_OVERRIDE], $result['not_found']);
        $this->assertSame([], $this->db->executed);
    }

    public function testManageRejectsTraversalPaths(): void
    {
        $this->assertToolError(
            $this->callTool('manage_template_updated_files', [
                'extension_id' => self::EXTENSION_ID,
                'paths' => ['html/../../../configuration.php'],
                'action' => 'mark_checked',
            ], [[]]),
            'Invalid path'
        );
    }

    public function testDiffComparesTheCoreFileWithTheOverride(): void
    {
        $result = $this->toolResult($this->callTool('diff_template_override', [
            'extension_id' => self::EXTENSION_ID,
            'path' => self::ARTICLE_OVERRIDE,
        ]));

        $this->assertSame([['/' . self::ARTICLE_OVERRIDE, 0]], $this->coreFileLookups);
        $this->assertSame('components/com_content/tmpl/article/default.php', $result['core_file']);
        $this->assertFalse($result['identical']);
        $this->assertStringContainsString("+echo 'mine';", $result['diff']);
        $this->assertArrayNotHasKey('core_source', $result);
        $this->assertArrayNotHasKey('override_source', $result);
    }

    public function testDiffCanIncludeBothSources(): void
    {
        $result = $this->toolResult($this->callTool('diff_template_override', [
            'extension_id' => self::EXTENSION_ID,
            'path' => self::ARTICLE_OVERRIDE,
            'include_sources' => true,
        ]));

        $this->assertSame("<?php\necho 'core';\n", $result['core_source']);
        $this->assertSame("<?php\necho 'core';\necho 'mine';\n", $result['override_source']);
    }

    public function testDiffReportsAnOverrideWithNoCoreFile(): void
    {
        unlink(JPATH_ROOT . '/components/com_content/tmpl/article/default.php');

        $this->assertToolError(
            $this->callTool('diff_template_override', [
                'extension_id' => self::EXTENSION_ID,
                'path' => self::ARTICLE_OVERRIDE,
            ]),
            'No core file found for override'
        );
    }

    public function testDiffOnlyAcceptsHtmlOverrides(): void
    {
        file_put_contents($this->templateRoot . '/index.php', "<?php\n");

        $this->assertToolError(
            $this->callTool('diff_template_override', [
                'extension_id' => self::EXTENSION_ID,
                'path' => 'index.php',
            ]),
            'Only overrides under html/'
        );
    }

    private function record(string $path, int $state, string $action): object
    {
        return (object) [
            'hash_id' => base64_encode('/' . $path),
            'state' => $state,
            'action' => $action,
            'created_date' => '2026-01-01 10:00:00',
            'modified_date' => '2026-02-01 10:00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<list<object>>  $objectLists  Row lists for loadObjectList(), in call order
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments, array $objectLists = []): array
    {
        $this->db = new StubDatabase();
        $this->db->objects[] = (object) [
            'extension_id' => self::EXTENSION_ID,
            'element' => 'cassiopeia',
            'client_id' => 0,
        ];
        $this->db->objectLists = $objectLists;
        Factory::$dbo = $this->db;
        Factory::$application = $this->application();

        return $this->makeService()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * Just enough of the administrator application for bootTemplateModel():
     * a TemplateModel whose getCoreFile() maps html/com_content/{view}/{file}
     * to components/com_content/tmpl/{view}/{file}.
     */
    private function application(): object
    {
        $lookups = &$this->coreFileLookups;

        $model = new class ($lookups) {
            /** @param list<array{string,int}> $lookups */
            public function __construct(private array &$lookups)
            {
            }

            public function setState(string $key, mixed $value): void
            {
            }

            public function getCoreFile(string $file, int $clientId): string|false
            {
                $this->lookups[] = [$file, $clientId];
                $parts = explode('/', $file);
                $core = JPATH_ROOT . '/components/' . $parts[2] . '/tmpl/' . $parts[3] . '/' . $parts[4];

                return is_file($core) ? $core : false;
            }
        };

        return new class ($model) {
            public function __construct(private object $model)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function getInput(): object
            {
                return new class {
                    public function set(string $key, mixed $value): void
                    {
                    }
                };
            }

            public function bootComponent(string $component): object
            {
                $model = $this->model;

                return new class ($model) {
                    public function __construct(private object $model)
                    {
                    }

                    public function getMVCFactory(): object
                    {
                        $model = $this->model;

                        return new class ($model) {
                            public function __construct(private object $model)
                            {
                            }

                            public function createModel(string $name, string $prefix, array $config): object
                            {
                                return $this->model;
                            }
                        };
                    }
                };
            }
        };
    }

    private function makeService(): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        $policy->method('resourcesEnabled')->willReturn(false);
        $policy->method('promptsEnabled')->willReturn(false);

        return new RpcService(
            $this->createMock(RestClient::class),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function toolResult(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey(
            'isError',
            $response['result'],
            $response['result']['content'][0]['text'] ?? ''
        );
        $this->assertArrayHasKey('structuredContent', $response['result']);

        return $response['result']['structuredContent'];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertToolError(array $response, string $expectedMessage): void
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertTrue($response['result']['isError'] ?? false, 'Expected an error result');
        $this->assertStringContainsString($expectedMessage, $response['result']['content'][0]['text']);
    }

    private function makeDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o777, true) && !is_dir($path)) {
            $this->fail('Could not create test directory: ' . $path);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
