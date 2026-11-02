<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the com_redirect tool family. Every tool is API-backed, so the REST client is the only
 * seam these tests need.
 */
class RpcServiceRedirectsTest extends TestCase
{
    private const PATH = 'api/index.php/v1/redirects';

    public function testListRedirectsFiltersAndSortsLocally(): void
    {
        $queries = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            function (string $path, array $query = []) use (&$queries): array {
                $queries[] = $query;

                return ['data' => [
                    $this->link(1, '/old-a', '/new-a', 1, 5),
                    $this->link(2, '/Missing-Page', '', 0, 40),
                    $this->link(3, '/missing-other', '', 0, 90),
                    $this->link(4, '/missing-fixed', '/fixed', 1, 70),
                ]];
            }
        );

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'list_redirects', [
            'search' => 'MISSING',
            'unresolved' => true,
            'order_by' => 'hits',
            'direction' => 'desc',
            'limit' => 1,
        ]));

        // Joomla's redirect API ignores filter[] parameters, so nothing but paging goes upstream.
        $this->assertSame([['page[limit]' => 100, 'page[offset]' => 0]], $queries);
        $this->assertSame(['3'], array_column($result['data'], 'id'));
        $this->assertSame(2, $result['pagination']['total_count']);
        $this->assertTrue($result['pagination']['has_more']);
    }

    public function testListRedirectsFiltersByStateAndStatusCode(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            $this->link(1, '/a', '/x', 1, 0, 301),
            $this->link(2, '/b', '/y', 1, 0, 302),
            $this->link(3, '/c', '/z', 0, 0, 302),
        ]]);

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'list_redirects', [
            'published' => 1,
            'header' => 302,
        ]));

        $this->assertSame(['2'], array_column($result['data'], 'id'));
    }

    public function testCreateRedirectSendsDefaultsAndOmitsAnAbsentDestination(): void
    {
        $captured = [];
        $rest = $this->createRestMock();
        $rest->expects($this->once())->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return ['data' => ['id' => 9]];
            }
        );

        $this->callTool($this->makeService($rest), 'create_redirect', ['old_url' => '  /gone  ', 'header' => 410]);

        $this->assertSame(self::PATH, $captured['path']);
        $this->assertSame(['published' => 1, 'header' => 410, 'old_url' => '/gone'], $captured['body']);
    }

    public function testCreateRedirectNamesTheWebservicesPluginOn404(): void
    {
        $rest = $this->createRestMock();
        $rest->method('post')->willThrowException(new RequestException(
            'Not Found',
            new Request('POST', 'https://example.test'),
            new Response(404, [], '{"errors":[{"code":404}]}')
        ));

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'create_redirect', ['old_url' => '/a', 'new_url' => '/b']);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('plg_webservices_redirect', $this->errorText($response));
    }

    public function testUpdateRedirectSendsOnlySuppliedFields(): void
    {
        $captured = [];
        $rest = $this->createRestMock();
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return ['data' => ['id' => 4]];
            }
        );

        $this->callTool($this->makeService($rest), 'update_redirect', ['id' => 4, 'new_url' => '/fixed', 'published' => 1]);

        $this->assertSame(self::PATH . '/4', $captured['path']);
        $this->assertSame(['new_url' => '/fixed', 'published' => 1], $captured['body']);
    }

    public function testUpdateRedirectRejectsACallThatChangesNothing(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('patch');

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'update_redirect', ['id' => 4]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('No updatable fields supplied', $this->errorText($response));
    }

    public function testDeleteRedirectTrashesFirst(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => $this->link(4, '/a', '/b', 1, 0)]);
        $rest->expects($this->once())->method('patch')->with(self::PATH . '/4', ['published' => -2]);
        $rest->expects($this->once())->method('delete')->with(self::PATH . '/4')->willReturn([]);

        $this->callTool($this->makeService($rest), 'delete_redirect', ['id' => 4]);
    }

    public function testDeleteRedirectSkipsTrashingAnAlreadyTrashedLink(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => $this->link(4, '/a', '/b', -2, 0)]);
        $rest->expects($this->never())->method('patch');
        $rest->expects($this->once())->method('delete')->willReturn([]);

        $this->callTool($this->makeService($rest), 'delete_redirect', ['id' => 4]);
    }

    public function testWritesInvalidateTheCachedList(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->exactly(2))->method('get')->willReturn(['data' => []]);
        $rest->method('post')->willReturn(['data' => ['id' => 1]]);

        $service = $this->makeService($rest);
        $this->callTool($service, 'list_redirects', []);
        $this->callTool($service, 'list_redirects', []);
        $this->callTool($service, 'create_redirect', ['old_url' => '/a', 'new_url' => '/b']);
        $this->callTool($service, 'list_redirects', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function link(int $id, string $oldUrl, string $newUrl, int $published, int $hits, int $header = 301): array
    {
        return [
            'type' => 'links',
            'id' => (string) $id,
            'attributes' => [
                'id' => $id,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'comment' => '',
                'hits' => $hits,
                'published' => $published,
                'header' => $header,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function errorText(array $response): string
    {
        return (string) ($response['result']['content'][0]['text'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function toolResult(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('structuredContent', $response['result']);

        return $response['result']['structuredContent'];
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
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
