<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Joomla's media API answers a file write by re-reading the item with the model state the
 * write used, and that state holds the uploaded bytes under "content" — which the read
 * treats as "include content". Every upload therefore echoes the whole file back as base64.
 */
class RpcServiceMediaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function writeTools(): iterable
    {
        yield 'upload_media' => ['upload_media', 'post', ['path' => 'banners/logo.webp']];
        yield 'update_media' => ['update_media', 'patch', ['path' => 'local-images:/banners/logo.webp']];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('writeTools')]
    public function testFileWriteDoesNotEchoTheUploadedContentBack(string $tool, string $verb, array $arguments): void
    {
        $encoded = base64_encode(str_repeat("\x89RIFF-webp-bytes", 4096));

        $rest = $this->createMock(RestClient::class);
        $rest->method($verb)->willReturn([
            'links' => ['self' => 'https://example.org/api/index.php/v1/media/files'],
            'data' => [
                'type' => 'media',
                'id' => 'local-images:/banners/logo.webp',
                'attributes' => [
                    'type' => 'file',
                    'name' => 'logo.webp',
                    'path' => 'local-images:/banners/logo.webp',
                    'size' => 65536,
                    'mime_type' => 'image/webp',
                    'content' => $encoded,
                ],
            ],
        ]);

        $response = $this->makeService($rest)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments + ['content' => $encoded]],
        ]);

        $this->assertFalse($response['result']['isError'] ?? false);
        $serialized = json_encode($response['result']);
        $this->assertStringNotContainsString($encoded, $serialized);
        $this->assertStringContainsString('local-images:\/banners\/logo.webp', $serialized);
        $this->assertStringContainsString('image\/webp', $serialized);
    }

    private function makeService(RestClient $rest): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);

        return new RpcService(
            $rest,
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }
}
