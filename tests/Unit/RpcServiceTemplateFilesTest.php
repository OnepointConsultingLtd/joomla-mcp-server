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
use Joomla\Component\Mcpserver\Tests\Stubs\StubDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RpcServiceTemplateFilesTest extends TestCase
{
    private const EXTENSION_ID = 506;

    private string $templateRoot;

    protected function setUp(): void
    {
        $this->templateRoot = JPATH_ROOT . '/templates/cassiopeia';

        // A template with one existing override directory, as create_template_override leaves it.
        $this->makeDir($this->templateRoot . '/html/com_content/article');
        file_put_contents($this->templateRoot . '/html/com_content/article/default.php', "<?php // original\n");
        file_put_contents($this->templateRoot . '/index.php', "<?php // index\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree(JPATH_ROOT);
        Factory::reset();
    }

    public function testUpdateTemplateFileCreatesANewFileInAnExistingOverrideDirectory(): void
    {
        $result = $this->toolResult($this->callTool('update_template_file', [
            'extension_id' => self::EXTENSION_ID,
            'path' => 'html/com_content/article/default_custom.php',
            'source' => "<?php // new override\n",
        ]));

        $this->assertTrue($result['created']);
        $this->assertSame('html/com_content/article/default_custom.php', $result['path']);
        $this->assertFileExists($this->templateRoot . '/html/com_content/article/default_custom.php');
        $this->assertSame(
            "<?php // new override\n",
            file_get_contents($this->templateRoot . '/html/com_content/article/default_custom.php')
        );
    }

    public function testUpdateTemplateFileStillOverwritesAnExistingFile(): void
    {
        $result = $this->toolResult($this->callTool('update_template_file', [
            'extension_id' => self::EXTENSION_ID,
            'path' => 'html/com_content/article/default.php',
            'source' => "<?php // replaced\r\n",
        ]));

        $this->assertFalse($result['created']);
        // Line endings are still normalised to Unix.
        $this->assertSame(
            "<?php // replaced\n",
            file_get_contents($this->templateRoot . '/html/com_content/article/default.php')
        );
    }

    public function testUpdateTemplateFileRefusesToCreateAFileInAMissingDirectory(): void
    {
        $this->assertToolError(
            $this->callTool('update_template_file', [
                'extension_id' => self::EXTENSION_ID,
                'path' => 'html/com_contact/contact/default.php',
                'source' => "<?php\n",
            ]),
            'Directory does not exist'
        );

        $this->assertDirectoryDoesNotExist($this->templateRoot . '/html/com_contact');
    }

    public function testUpdateTemplateFileAppliesTheExtensionAllowlistToNewFiles(): void
    {
        $this->assertToolError(
            $this->callTool('update_template_file', [
                'extension_id' => self::EXTENSION_ID,
                'path' => 'html/com_content/article/shell.phtml',
                'source' => "<?php\n",
            ]),
            'File type is not editable'
        );

        $this->assertFileDoesNotExist($this->templateRoot . '/html/com_content/article/shell.phtml');
    }

    public function testUpdateTemplateFileRefusesToCreateOutsideTheTemplateRoot(): void
    {
        $this->assertToolError(
            $this->callTool('update_template_file', [
                'extension_id' => self::EXTENSION_ID,
                'path' => '../../configuration.php',
                'source' => "<?php\n",
            ]),
            'Invalid path'
        );

        $this->assertFileDoesNotExist(JPATH_ROOT . '/configuration.php');
    }

    public function testUpdateTemplateFileValidatesANewAssetManifest(): void
    {
        $this->assertToolError(
            $this->callTool('update_template_file', [
                'extension_id' => self::EXTENSION_ID,
                'path' => 'joomla.asset.json',
                'source' => 'not json',
            ]),
            'joomla.asset.json must contain valid JSON'
        );

        $this->assertFileDoesNotExist($this->templateRoot . '/joomla.asset.json');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        $db = new StubDatabase();
        $db->objects[] = (object) [
            'extension_id' => self::EXTENSION_ID,
            'element' => 'cassiopeia',
            'client_id' => 0,
        ];
        Factory::$database = $db;

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
