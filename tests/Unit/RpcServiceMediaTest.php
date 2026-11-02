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
use Joomla\Registry\Registry;
use Psr\Log\LoggerInterface;

/**
 * Joomla's media API answers a file write by re-reading the item with the model state the
 * write used, and that state holds the uploaded bytes under "content" — which the read
 * treats as "include content". Every upload therefore echoes the whole file back as base64.
 */
class RpcServiceMediaTest extends TestCase
{
    protected function setUp(): void
    {
        // com_media's options left at Joomla's defaults: every media type has extensions.
        ComponentHelper::$params = new Registry([]);
    }

    protected function tearDown(): void
    {
        ComponentHelper::reset();
    }

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

    /**
     * local_path names a file on the client's machine; the bundled bridge swaps it for
     * "content". Arriving here means the client bypassed the bridge, and the path must
     * not be read on the server or silently ignored.
     *
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('writeTools')]
    public function testUnresolvedLocalPathIsRefusedWithoutCallingJoomla(string $tool, string $verb, array $arguments): void
    {
        $rest = $this->createMock(RestClient::class);
        $rest->expects($this->never())->method($verb);

        $response = $this->makeService($rest)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments + ['local_path' => '/home/me/logo.webp']],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('bundled MCP bridge', json_encode($response['result']));
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function fileTools(): iterable
    {
        yield 'list_media' => ['list_media', 'get', ['path' => 'local-images:/videos']];
        yield 'get_media' => ['get_media', 'get', ['path' => 'local-images:/videos/clip.mp4']];
        yield 'upload_media' => ['upload_media', 'post', ['path' => 'videos/clip.mp4', 'content' => 'AAAA']];
        yield 'update_media' => ['update_media', 'patch', ['path' => 'local-images:/videos/clip.mp4', 'content' => 'AAAA']];
        yield 'delete_media' => ['delete_media', 'delete', ['path' => 'local-images:/videos/clip.mp4']];
    }

    /**
     * Joomla's media API treats a request without "mediatypes" as images-only, so a
     * video, audio file or PDF would be hidden from listings and refused on every
     * other call with "File type not allowed".
     *
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('fileTools')]
    public function testEveryFileCallAllowsAllMediaTypes(string $tool, string $verb, array $arguments): void
    {
        $this->assertStringContainsString(
            $verb === 'get' ? 'mediatypes=0%2C1%2C2%2C3' : '?mediatypes=0,1,2,3',
            $this->sentMediaRequest($tool, $verb, $arguments)
        );
    }

    /**
     * An admin who clears a type's extension list in the Media options has turned that
     * type off; it is not requested, so Joomla keeps refusing those files.
     */
    public function testMediaTypesFollowTheConfiguredExtensionLists(): void
    {
        ComponentHelper::$params = new Registry([
            'image_extensions' => 'jpg,png',
            'audio_extensions' => '',
            'video_extensions' => ' , ',
            'doc_extensions' => 'pdf',
        ]);

        $this->assertStringEndsWith(
            '?mediatypes=0,3',
            $this->sentMediaRequest('delete_media', 'delete', ['path' => 'local-images:/docs/a.pdf'])
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return string  the path the tool sent, then "|" and its query array if it had one
     */
    private function sentMediaRequest(string $tool, string $verb, array $arguments): string
    {
        $sent = null;
        $rest = $this->createMock(RestClient::class);
        $rest->expects($this->once())->method($verb)->willReturnCallback(
            function (string $path, array $extra = []) use (&$sent): array {
                $sent = $path . ($extra !== [] ? '|' . http_build_query($extra) : '');
                return ['data' => []];
            }
        );

        $response = $this->makeService($rest)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        $this->assertFalse($response['result']['isError'] ?? false, json_encode($response));

        // GET sends it in the query array (Guzzle's "query" option replaces a URL query
        // string); the writes carry it on the URL.
        return (string) $sent;
    }

    public function testMediaAndPackageToolsAnnotateLocalPathForTheBridge(): void
    {
        $registry = new ToolRegistry();
        foreach (['upload_media', 'update_media', 'install_extension'] as $name) {
            $property = $registry->get($name)['inputSchema']['properties']['local_path'] ?? null;
            $this->assertSame('content', $property['x-mcp-local-file'] ?? null, $name);
        }
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
