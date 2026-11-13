<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\McpbService;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

class McpbServiceTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = JPATH_ADMINISTRATOR . '/components/com_mcpserver/mcpb';
        mkdir($this->templateDir . '/server', 0777, true);

        file_put_contents($this->templateDir . '/manifest.json', json_encode([
            'manifest_version' => '0.3',
            'name' => 'joomla-mcp-server',
            'display_name' => 'MCP Server for Joomla',
            'user_config' => [
                'endpoint_url' => ['type' => 'string'],
            ],
        ]));
        file_put_contents($this->templateDir . '/icon.png', 'icon');
        file_put_contents($this->templateDir . '/server/mcp-http-bridge.js', 'bridge');
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->templateDir . '/server/mcp-http-bridge.js',
            $this->templateDir . '/icon.png',
            $this->templateDir . '/manifest.json',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->templateDir . '/server')) {
            rmdir($this->templateDir . '/server');
        }
        if (is_dir($this->templateDir)) {
            rmdir($this->templateDir);
        }
    }

    public function testBuildBundleThrowsWhenATemporaryFileCannotBeCreated(): void
    {
        $service = new class (new Registry(['base_url' => 'https://example.test'])) extends McpbService {
            protected function createTempFile(): string|false
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not create a temporary file for the bundle archive.');

        $service->buildBundle('Example Site');
    }

    public function testBundleIsNamedAfterTheSite(): void
    {
        $manifest = $this->buildManifest('https://Manhattan.Example.org/', 'Meditation Center  Manhattan');

        $this->assertSame('joomla-mcp-server-manhattan-example-org', $manifest['name']);
        $this->assertSame('Meditation Center Manhattan', $manifest['display_name']);
        $this->assertSame(
            'https://Manhattan.Example.org/index.php?option=com_mcpserver&task=rpc.handle',
            $manifest['user_config']['endpoint_url']['default']
        );
    }

    public function testSitesSharingAHostGetDistinctNames(): void
    {
        $this->assertSame(
            'joomla-mcp-server-example-com-8080-shop',
            $this->buildManifest('http://example.com:8080/shop', 'Shop')['name']
        );
        $this->assertSame(
            'joomla-mcp-server-example-com-blog',
            $this->buildManifest('http://example.com/blog', 'Blog')['name']
        );
    }

    public function testSiteSlugNamesTheSiteForClientConfiguration(): void
    {
        $this->assertSame(
            'manhattan-example-org',
            (new McpbService(new Registry(['base_url' => 'https://Manhattan.Example.org/'])))->siteSlug()
        );
        $this->assertSame(
            'example-com-8080-shop',
            (new McpbService(new Registry(['base_url' => 'http://example.com:8080/shop'])))->siteSlug()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(string $baseUrl, string $siteName): array
    {
        $bundlePath = (new McpbService(new Registry(['base_url' => $baseUrl])))->buildBundle($siteName);

        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($bundlePath));
            $json = $zip->getFromName('manifest.json');
            $zip->close();
        } finally {
            unlink($bundlePath);
        }

        $this->assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
