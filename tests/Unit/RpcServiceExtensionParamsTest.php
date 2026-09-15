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
use Joomla\Component\Mcpserver\Tests\Stubs\StubCacheController;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FakeExtensionQuery implements QueryInterface
{
    /** @var list<string> */
    public array $whereConditions = [];

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
        foreach ((array) $conditions as $condition) {
            $this->whereConditions[] = $condition;
        }

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
        return 'WHERE ' . implode(' AND ', $this->whereConditions);
    }
}

final class FakeExtensionDatabase implements DatabaseInterface
{
    /** Rows loadAssoc()/loadAssocList() hand back, oldest queued first. @var list<list<array<string, mixed>>> */
    public array $resultSets = [];

    /** @var list<array{table: string, row: object}> */
    public array $updatedObjects = [];

    /** The WHERE conditions of every query run, in order. @var list<list<string>> */
    public array $queried = [];

    private ?FakeExtensionQuery $query = null;

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        if (is_array($name)) {
            return array_map(static fn (string $one): string => '`' . $one . '`', $name);
        }

        return '`' . $name . '`';
    }

    public function quote(array|string $text, bool $escape = true): array|string
    {
        if (is_array($text)) {
            return array_map(static fn (string $one): string => "'" . addslashes($one) . "'", $text);
        }

        return "'" . addslashes($text) . "'";
    }

    public function getQuery(bool $new = false): QueryInterface|string
    {
        return new FakeExtensionQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->query = $query instanceof FakeExtensionQuery ? $query : null;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->nextResultSet()[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAssocList(): array
    {
        return $this->nextResultSet();
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function updateObject(string $table, object $object, string $key): bool
    {
        $this->updatedObjects[] = ['table' => $table, 'row' => clone $object];

        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextResultSet(): array
    {
        $this->queried[] = $this->query?->whereConditions ?? [];

        return array_shift($this->resultSets) ?? [];
    }
}

class RpcServiceExtensionParamsTest extends TestCase
{
    private FakeExtensionDatabase $db;

    /** Manifest fixtures written for a test, removed again in tearDown. @var list<string> */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        Factory::reset();

        $this->db = new FakeExtensionDatabase();
        Factory::$dbo = $this->db;
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->writtenFiles = [];

        Factory::reset();
    }

    public function testReadReturnsStoredParamsAndManifestDefinitions(): void
    {
        $this->writePluginManifest('example', 'system', <<<'XML'
            <extension type="plugin" group="system">
                <config>
                    <fields name="params">
                        <fieldset name="basic">
                            <field name="target_field" type="text" label="PLG_EXAMPLE_TARGET_FIELD_LABEL" default="" />
                            <field name="limit" type="number" label="PLG_EXAMPLE_LIMIT_LABEL" default="10" />
                            <field name="mode" type="list" label="PLG_EXAMPLE_MODE_LABEL" default="all">
                                <option value="all">All</option>
                                <option value="some">Some</option>
                            </field>
                            <field type="spacer" name="spacer_one" />
                        </fieldset>
                    </fields>
                </config>
            </extension>
            XML);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"25"}'])];

        $data = $this->call('get_extension_params', ['extension_id' => 42]);

        $this->assertSame(42, $data['extension_id']);
        $this->assertSame('plugin', $data['type']);
        $this->assertSame('site', $data['client']);
        $this->assertSame(['limit' => '25'], (array) $data['params']);
        $this->assertSame('plugins/system/example/example.xml', $data['manifest_path']);
        $this->assertSame(
            ['target_field', 'limit', 'mode'],
            array_column($data['option_definitions'], 'name')
        );

        $mode = $data['option_definitions'][2];
        $this->assertSame('list', $mode['type']);
        $this->assertSame('all', $mode['default']);
        $this->assertSame('basic', $mode['fieldset']);
        $this->assertSame([['value' => 'all', 'label' => 'All'], ['value' => 'some', 'label' => 'Some']], $mode['options']);
    }

    /**
     * An extension whose Options have never been saved stores nothing at all. The
     * manifest is then the only way to learn which keys it accepts, which is the
     * whole reason the definitions are returned.
     */
    public function testNeverSavedExtensionReportsEmptyParamsWithDefinitions(): void
    {
        $this->writePluginManifest('example', 'system', <<<'XML'
            <extension type="plugin" group="system">
                <config>
                    <fields name="params">
                        <fieldset name="basic">
                            <field name="limit" type="number" default="10" />
                        </fieldset>
                    </fields>
                </config>
            </extension>
            XML);

        $this->db->resultSets[] = [$this->extensionRow(['params' => ''])];

        $data = $this->call('get_extension_params', ['extension_id' => 42]);

        $this->assertSame([], (array) $data['params']);
        $this->assertSame(['limit'], array_column($data['option_definitions'], 'name'));
        $this->assertSame('10', $data['option_definitions'][0]['default']);
    }

    public function testPasswordOptionsAreMaskedOnRead(): void
    {
        $this->writePluginManifest('example', 'system', <<<'XML'
            <extension type="plugin" group="system">
                <config>
                    <fields name="params">
                        <fieldset name="basic">
                            <field name="api_key" type="password" />
                            <field name="limit" type="number" />
                        </fieldset>
                    </fields>
                </config>
            </extension>
            XML);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"api_key":"s3cret","limit":"25"}'])];

        $data = $this->call('get_extension_params', ['extension_id' => 42]);

        $this->assertSame(['api_key' => '********', 'limit' => '25'], (array) $data['params']);
        $this->assertSame(['api_key'], $data['redacted_keys']);
    }

    /**
     * The definitions are what identify a password field, so asking for a smaller
     * response must not be a way to read one in the clear.
     */
    public function testPasswordOptionsStayMaskedWithoutDefinitions(): void
    {
        $this->writePluginManifest('example', 'system', <<<'XML'
            <extension type="plugin" group="system">
                <config>
                    <fields name="params">
                        <fieldset name="basic">
                            <field name="api_key" type="password" />
                        </fieldset>
                    </fields>
                </config>
            </extension>
            XML);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"api_key":"s3cret"}'])];

        $data = $this->call('get_extension_params', ['extension_id' => 42, 'include_definitions' => false]);

        $this->assertSame(['api_key' => '********'], (array) $data['params']);
        $this->assertSame(['api_key'], $data['redacted_keys']);
        $this->assertArrayNotHasKey('option_definitions', $data);
    }

    /**
     * A component declares its options in config.xml, whose root element is the
     * <config> block itself rather than a manifest wrapping one.
     */
    public function testComponentOptionsAreReadFromConfigXml(): void
    {
        $this->writeManifest(
            JPATH_ADMINISTRATOR . '/components/com_example/config.xml',
            <<<'XML'
            <config>
                <fieldset name="integration">
                    <field name="feed_limit" type="number" default="5" />
                </fieldset>
            </config>
            XML
        );

        $this->db->resultSets[] = [$this->extensionRow([
            'type' => 'component',
            'element' => 'com_example',
            'folder' => '',
            'client_id' => 1,
            'params' => '{"feed_limit":"9"}',
        ])];

        $data = $this->call('get_extension_params', ['extension_id' => 42]);

        $this->assertSame('administrator', $data['client']);
        $this->assertSame(['feed_limit' => '9'], (array) $data['params']);
        $this->assertSame(['feed_limit'], array_column($data['option_definitions'], 'name'));
    }

    public function testMissingManifestLeavesDefinitionsEmpty(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"25"}'])];

        $data = $this->call('get_extension_params', ['extension_id' => 42]);

        $this->assertNull($data['manifest_path']);
        $this->assertSame([], $data['option_definitions']);
        $this->assertSame(['limit' => '25'], (array) $data['params']);
    }

    public function testLookupByElementFiltersOnTypeFolderAndClient(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{}'])];

        $this->call('get_extension_params', [
            'element' => 'example',
            'type' => 'plugin',
            'folder' => 'system',
            'client' => 'site',
        ]);

        $this->assertSame(
            [
                "`element` = 'example'",
                "`type` = 'plugin'",
                "`folder` = 'system'",
                '`client_id` = 0',
            ],
            $this->db->queried[0]
        );
    }

    public function testAmbiguousElementNamesTheCandidates(): void
    {
        $this->db->resultSets[] = [
            $this->extensionRow(['extension_id' => 42, 'folder' => 'system']),
            $this->extensionRow(['extension_id' => 43, 'folder' => 'content']),
        ];

        $error = $this->callExpectingError('get_extension_params', ['element' => 'example', 'type' => 'plugin']);

        $this->assertStringContainsString('matches 2 installed extensions', $error);
        $this->assertStringContainsString('#42 (folder system, site)', $error);
        $this->assertStringContainsString('#43 (folder content, site)', $error);
    }

    public function testUnknownElementIsReported(): void
    {
        $this->db->resultSets[] = [];

        $error = $this->callExpectingError('get_extension_params', ['element' => 'nope', 'type' => 'plugin']);

        $this->assertStringContainsString('No plugin extension is installed with element nope', $error);
    }

    public function testElementWithoutTypeIsRejected(): void
    {
        $error = $this->callExpectingError('get_extension_params', ['element' => 'example']);

        $this->assertStringContainsString('Supply extension_id, or both element and type', $error);
    }

    /**
     * Reading this component's own options would hand out the MCP bearer token and
     * the Joomla API token; writing them would switch off the policy layer that
     * gates the tool doing the writing.
     */
    public function testOwnComponentOptionsAreRefused(): void
    {
        foreach (['get_extension_params', 'update_extension_params'] as $tool) {
            $this->db->resultSets[] = [$this->extensionRow([
                'type' => 'component',
                'element' => 'com_mcpserver',
                'folder' => '',
                'client_id' => 1,
            ])];

            $error = $this->callExpectingError($tool, ['extension_id' => 42, 'params' => ['read_only' => 0]]);

            $this->assertStringContainsString('administrator', $error, $tool);
            $this->assertSame([], $this->db->updatedObjects, $tool);
        }
    }

    public function testUpdateMergesOnlyTheSuppliedKeys(): void
    {
        $this->db->resultSets[] = [$this->extensionRow([
            'params' => '{"target_field":"promo","limit":"10","mode":"all"}',
        ])];

        $data = $this->call('update_extension_params', [
            'extension_id' => 42,
            'params' => ['limit' => 25],
        ]);

        $this->assertCount(1, $this->db->updatedObjects);
        $this->assertSame('#__extensions', $this->db->updatedObjects[0]['table']);
        $this->assertSame(42, $this->db->updatedObjects[0]['row']->extension_id);
        $this->assertSame(
            ['target_field' => 'promo', 'limit' => 25, 'mode' => 'all'],
            json_decode($this->db->updatedObjects[0]['row']->params, true)
        );
        $this->assertSame(['limit'], $data['changed_keys']);
        $this->assertSame([], $data['removed_keys']);
    }

    public function testUpdateRemovesAKeySentAsNull(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"10","mode":"all"}'])];

        $data = $this->call('update_extension_params', [
            'extension_id' => 42,
            'params' => ['mode' => null],
        ]);

        $this->assertSame(['limit' => '10'], json_decode($this->db->updatedObjects[0]['row']->params, true));
        $this->assertSame(['mode'], $data['removed_keys']);
        $this->assertSame([], $data['changed_keys']);
    }

    /**
     * Joomla writes an empty params column as {}; json_encode of an empty PHP array
     * would store [], which its Registry reads back as a list rather than options.
     */
    public function testEmptiedParamsAreStoredAsAnObject(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"10"}'])];

        $this->call('update_extension_params', ['extension_id' => 42, 'params' => ['limit' => null]]);

        $this->assertSame('{}', $this->db->updatedObjects[0]['row']->params);
    }

    public function testUpdateReportsKeysTheManifestDoesNotDeclare(): void
    {
        $this->writePluginManifest('example', 'system', <<<'XML'
            <extension type="plugin" group="system">
                <config>
                    <fields name="params">
                        <fieldset name="basic">
                            <field name="limit" type="number" />
                        </fieldset>
                    </fields>
                </config>
            </extension>
            XML);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{}'])];

        $data = $this->call('update_extension_params', [
            'extension_id' => 42,
            'params' => ['limit' => 25, 'limti' => 5],
        ]);

        $this->assertSame(['limti'], $data['unknown_keys']);
        // Still written: extensions legitimately store options their manifest never
        // declares, so an undeclared key is reported rather than refused.
        $this->assertSame(['limit' => 25, 'limti' => 5], json_decode($this->db->updatedObjects[0]['row']->params, true));
    }

    public function testUpdateWithoutAManifestClaimsNoUnknownKeys(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{}'])];

        $data = $this->call('update_extension_params', ['extension_id' => 42, 'params' => ['limit' => 25]]);

        $this->assertSame([], $data['unknown_keys']);
    }

    public function testUpdateRejectsEmptyParams(): void
    {
        $error = $this->callExpectingError('update_extension_params', ['extension_id' => 42, 'params' => []]);

        $this->assertStringContainsString('at least one option', $error);
        $this->assertSame([], $this->db->updatedObjects);
    }

    public function testUpdateInvalidatesTheCachedRead(): void
    {
        $cache = new CacheService(new SimpleArrayCache());
        $service = $this->makeService($cache);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"10"}'])];
        $this->assertSame(['limit' => '10'], (array) $this->call('get_extension_params', ['extension_id' => 42], $service)['params']);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":"10"}'])];
        $this->call('update_extension_params', ['extension_id' => 42, 'params' => ['limit' => 25]], $service);

        $this->db->resultSets[] = [$this->extensionRow(['params' => '{"limit":25}'])];
        $this->assertSame(['limit' => 25], (array) $this->call('get_extension_params', ['extension_id' => 42], $service)['params']);
    }

    /**
     * PluginHelper serves the enabled plugins, params included, out of Joomla's own
     * com_plugins cache group. Without clearing it the site keeps running the old
     * settings until that cache expires, which reads as "the write did not work".
     */
    public function testUpdatingAPluginClearsJoomlasPluginCache(): void
    {
        $this->db->resultSets[] = [$this->extensionRow(['params' => '{}'])];

        $this->call('update_extension_params', ['extension_id' => 42, 'params' => ['limit' => 25]]);

        $this->assertSame(['com_plugins'], StubCacheController::$cleaned);
    }

    public function testUpdateIsRefusedInReadOnlyMode(): void
    {
        $service = $this->makeService(null, true);

        $error = $this->callExpectingError('update_extension_params', ['extension_id' => 42, 'params' => ['limit' => 25]], $service);

        $this->assertStringContainsString('read-only', strtolower($error));
        $this->assertSame([], $this->db->updatedObjects);
    }

    /**
     * Both tools ship in the Disabled Tools default, so a fresh install cannot read
     * an extension's secrets or reconfigure one until an administrator opts in.
     */
    public function testBothToolsAreDisabledByDefault(): void
    {
        $policy = new PolicyService(new \Joomla\Registry\Registry());

        $this->assertFalse($policy->isToolAllowed('get_extension_params'));
        $this->assertFalse($policy->isToolAllowed('update_extension_params'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function extensionRow(array $overrides = []): array
    {
        return $overrides + [
            'extension_id' => 42,
            'name' => 'PLG_SYSTEM_EXAMPLE',
            'type' => 'plugin',
            'element' => 'example',
            'folder' => 'system',
            'client_id' => 0,
            'enabled' => 1,
            'protected' => 0,
            'locked' => 0,
            'params' => '{}',
        ];
    }

    private function writePluginManifest(string $element, string $folder, string $xml): void
    {
        $this->writeManifest(JPATH_ROOT . '/plugins/' . $folder . '/' . $element . '/' . $element . '.xml', $xml);
    }

    private function writeManifest(string $path, string $xml): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $xml);
        $this->writtenFiles[] = $path;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments, ?RpcService $service = null): array
    {
        $response = $this->callTool($service ?? $this->makeService(), $tool, $arguments);

        $this->assertFalse(
            $response['result']['isError'] ?? false,
            (string) ($response['result']['content'][0]['text'] ?? '')
        );

        return json_decode($response['result']['content'][0]['text'], true)['data'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callExpectingError(string $tool, array $arguments, ?RpcService $service = null): string
    {
        $response = $this->callTool($service ?? $this->makeService(), $tool, $arguments);

        $this->assertTrue($response['result']['isError'] ?? false);

        return (string) $response['result']['content'][0]['text'];
    }

    private function makeService(?CacheService $cache = null, bool $readOnly = false): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn($readOnly);

        return new RpcService(
            $this->createMock(RestClient::class),
            $cache ?? new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(RpcService $service, string $name, array $arguments): array
    {
        return $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }
}
