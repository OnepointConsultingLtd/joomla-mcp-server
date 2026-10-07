<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
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

class RpcServiceResourcesTest extends TestCase
{
    public function testInitializeAdvertisesResourcesWhenEnabled(): void
    {
        $response = $this->makeService(resourcesEnabled: true)->handle($this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
        ]));

        $this->assertSame(
            ['subscribe' => false, 'listChanged' => false],
            $response['result']['capabilities']['resources']
        );
        $this->assertArrayNotHasKey('prompts', $response['result']['capabilities']);
    }

    public function testInitializeOmitsResourcesWhenDisabled(): void
    {
        $response = $this->makeService()->handle($this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
        ]));

        $this->assertArrayNotHasKey('resources', $response['result']['capabilities']);
    }

    public function testListResourcesMapsPublishedArticlesNewestFirst(): void
    {
        $capturedQuery = null;
        $rest = $this->createRestMock();
        $rest->expects($this->once())->method('get')->willReturnCallback(
            static function (string $path, array $query = []) use (&$capturedQuery): array {
                $capturedQuery = ['path' => $path, 'query' => $query];

                return [
                    'data' => [
                        [
                            'id' => '3',
                            'attributes' => [
                                'title' => 'Older',
                                'alias' => 'older-article',
                                'introtext' => '<p>Older intro</p>',
                            ],
                        ],
                        [
                            'id' => '10',
                            'attributes' => [
                                'title' => 'Newer',
                                'alias' => '',
                                // Multibyte on purpose: a byte-based truncation
                                // would split a character and break json_encode.
                                'introtext' => '<p>' . str_repeat('é', 250) . '</p>',
                            ],
                        ],
                    ],
                ];
            }
        );

        $response = $this->makeService($rest, true)->handle($this->rpc('resources/list'));

        $this->assertSame('api/index.php/v1/content/articles', $capturedQuery['path']);
        $this->assertSame(1, $capturedQuery['query']['filter[state]']);
        $this->assertSame(50, $capturedQuery['query']['page[limit]']);

        $resources = $response['result']['resources'];
        $this->assertCount(2, $resources);
        $this->assertSame('joomla://article/10', $resources[0]['uri']);
        $this->assertSame('article-10', $resources[0]['name']);
        $this->assertSame('Newer', $resources[0]['title']);
        $this->assertSame('text/html', $resources[0]['mimeType']);
        $this->assertSame(200, mb_strlen($resources[0]['description']));
        $this->assertNotFalse(json_encode($response));
        $this->assertSame('joomla://article/3', $resources[1]['uri']);
        $this->assertSame('older-article', $resources[1]['name']);
        $this->assertSame('Older intro', $resources[1]['description']);
    }

    public function testListResourcesReturnsEmptyAndSkipsRestWhenDisabled(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('get');

        $response = $this->makeService($rest, false)->handle($this->rpc('resources/list'));

        $this->assertSame(['resources' => []], $response['result']);
    }

    public function testListResourceTemplatesReturnsArticleTemplate(): void
    {
        $response = $this->makeService(resourcesEnabled: true)->handle($this->rpc('resources/templates/list'));

        $this->assertSame('joomla://article/{id}', $response['result']['resourceTemplates'][0]['uriTemplate']);
        $this->assertSame('text/html', $response['result']['resourceTemplates'][0]['mimeType']);
    }

    public function testListResourceTemplatesReturnsEmptyWhenDisabled(): void
    {
        $response = $this->makeService()->handle($this->rpc('resources/templates/list'));

        $this->assertSame(['resourceTemplates' => []], $response['result']);
    }

    public function testReadResourceReturnsHtmlBody(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn([
            // No top-level data.id so injectRawArticleContent skips Factory::getDbo().
            'data' => [
                'attributes' => [
                    'introtext' => '<p>Intro</p>',
                    'fulltext' => '<p>Full</p>',
                ],
            ],
        ]);

        $response = $this->makeService($rest, true)->handle($this->rpc('resources/read', [
            'uri' => 'joomla://article/7',
        ]));

        $this->assertSame([
            [
                'uri' => 'joomla://article/7',
                'mimeType' => 'text/html',
                'text' => '<p>Intro</p><p>Full</p>',
            ],
        ], $response['result']['contents']);
    }

    public function testReadResourceRejectsMissingUri(): void
    {
        $response = $this->makeService(resourcesEnabled: true)->handle($this->rpc('resources/read'));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame('Resource URI is required', $response['error']['message']);
    }

    public function testReadResourceRejectsMalformedUri(): void
    {
        $response = $this->makeService(resourcesEnabled: true)->handle($this->rpc('resources/read', [
            'uri' => 'joomla://category/1',
        ]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame('Resource not found: joomla://category/1', $response['error']['message']);
    }

    public function testReadResourceMapsGuzzle404ToInvalidParams(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willThrowException(new ClientException(
            'Not Found',
            new Request('GET', 'api/index.php/v1/content/articles/999'),
            new Response(404, [], '{"errors":[{"title":"Not found"}]}')
        ));

        $response = $this->makeService($rest, true)->handle($this->rpc('resources/read', [
            'uri' => 'joomla://article/999',
        ]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame('Resource not found: joomla://article/999', $response['error']['message']);
    }

    public function testReadResourceReturnsMethodNotFoundWhenDisabled(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('get');

        $response = $this->makeService($rest, false)->handle($this->rpc('resources/read', [
            'uri' => 'joomla://article/1',
        ]));

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
        $this->assertSame('Resources are disabled by server policy', $response['error']['message']);
    }

    public function testListResourcesAnnotatesLastModified(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            ['id' => 3, 'attributes' => ['title' => 'A', 'alias' => 'a', 'introtext' => '', 'modified' => '2026-09-01 10:00:00']],
            ['id' => 2, 'attributes' => ['title' => 'B', 'alias' => 'b', 'introtext' => '', 'modified' => '0000-00-00 00:00:00']],
            ['id' => 1, 'attributes' => ['title' => 'C', 'alias' => 'c', 'introtext' => '']],
        ]]);

        $resources = $this->makeService($rest, true)->handle($this->rpc('resources/list'))['result']['resources'];

        $this->assertSame(['lastModified' => '2026-09-01T10:00:00+00:00'], $resources[0]['annotations']);
        $this->assertArrayNotHasKey('annotations', $resources[1], 'a zero date is no date');
        $this->assertArrayNotHasKey('annotations', $resources[2]);
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
    }

    private function makeService(?RestClient $rest = null, bool $resourcesEnabled = false): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        $policy->method('resourcesEnabled')->willReturn($resourcesEnabled);
        $policy->method('promptsEnabled')->willReturn(false);

        return new RpcService(
            $rest ?? $this->createRestMock(),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
        ];
        if ($params !== []) {
            $request['params'] = $params;
        }

        return $request;
    }
}
