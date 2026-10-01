<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Controller\RpcHandlerTrait;
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RpcHandlerTransportTestHost
{
    use RpcHandlerTrait;
}

/**
 * The trait's transport wiring that can be exercised without an HTTP request:
 * dispatch failure handling, the Origin gate and request labelling. The rules
 * themselves are covered in McpHttpTransportTest.
 */
final class RpcHandlerTransportTest extends TestCase
{
    private ?string $savedOrigin = null;

    protected function setUp(): void
    {
        $this->savedOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedOrigin === null) {
            unset($_SERVER['HTTP_ORIGIN']);
        } else {
            $_SERVER['HTTP_ORIGIN'] = $this->savedOrigin;
        }
    }

    public function testDispatchFailureBecomesAnInternalErrorResponse(): void
    {
        // Without this, an unexpected throwable is an HTML error page and the
        // request never reaches the audit trail.
        $service = $this->createMock(RpcService::class);
        $service->method('handle')->willThrowException(new \TypeError('boom at /var/www/secret/path.php'));

        [$response, $failed] = $this->invoke('dispatchToService', $service, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call'], 'tools/call');

        $this->assertTrue($failed);
        $this->assertSame(4, $response['id']);
        $this->assertSame(JsonRpc::INTERNAL_ERROR, $response['error']['code']);
        $this->assertSame('Internal error', $response['error']['message'], 'internal details must not reach the client');
    }

    public function testDispatchPassesTheServiceResponseThrough(): void
    {
        $service = $this->createMock(RpcService::class);
        $service->method('handle')->willReturn(JsonRpc::successResponse(1, ['tools' => []]));

        [$response, $failed] = $this->invoke('dispatchToService', $service, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], 'tools/list');

        $this->assertFalse($failed);
        $this->assertSame(['tools' => []], $response['result']);
    }

    #[DataProvider('origins')]
    public function testOriginGate(?string $origin, string $allowedOrigins, bool $expected): void
    {
        if ($origin === null) {
            unset($_SERVER['HTTP_ORIGIN']);
        } else {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }

        $this->assertSame($expected, $this->invoke('isOriginAcceptable', new Registry(['allowed_origins' => $allowedOrigins])));
    }

    public static function origins(): array
    {
        return [
            'no Origin (a non-browser client)' => [null, '', true],
            'listed among several' => ['https://b.example', 'https://a.example, https://b.example', true],
            'not listed' => ['https://evil.example', 'https://a.example', false],
            'no list at all' => ['https://a.example', '', false],
            // Uri::root() derives from the Host header, which a DNS-rebinding page
            // controls: matching it must not count as "same origin".
            'the origin Uri::root() reports' => [rtrim(\Joomla\CMS\Uri\Uri::$root, '/'), '', false],
        ];
    }

    public function testNonStringNamesAreNotUsedAsAuditLabels(): void
    {
        $label = $this->invoke('extractToolName', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => ['delete_article']],
        ]);

        $this->assertSame('', $label);
    }

    public function testParamHeadersComeFromTheToolsSchema(): void
    {
        $this->assertSame(['id' => 'Id'], $this->invoke('paramHeadersFor', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_article_by_id']]));
        $this->assertSame([], $this->invoke('paramHeadersFor', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    }

    public function testTheHandlerBuildsAnUnstartedHttpSink(): void
    {
        $sink = $this->invoke('createProgressSink');

        $this->assertInstanceOf(\Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink::class, $sink);
        $this->assertFalse($sink->hasStarted());
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(RpcHandlerTransportTestHost::class, $method);

        return $reflection->invoke(new RpcHandlerTransportTestHost(), ...$arguments);
    }
}
