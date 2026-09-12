<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Event\Cache\AfterPurgeEvent;
use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
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

class RpcServiceTest extends TestCase
{
    public function testConstructionPairsEverySchemaWithAnExecutor(): void
    {
        $registry = new ToolRegistry();

        $this->makeService($registry);

        foreach ($registry->getAll() as $tool) {
            $this->assertTrue(
                $registry->hasExecutor($tool['name']),
                "Tool '{$tool['name']}' has a schema but no executor"
            );
        }
    }

    public function testConstructionThrowsWhenASchemaHasNoExecutor(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'orphan_schema',
            'description' => 'Registered without an executor',
            'inputSchema' => ['type' => 'object'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Tool 'orphan_schema' has a schema but no executor");

        $this->makeService($registry);
    }

    public function testToolsListExposesEveryRegisteredSchema(): void
    {
        $registry = new ToolRegistry();
        $service = $this->makeService($registry);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $listed = array_column($response['result']['tools'], 'name');
        $registered = array_column($registry->getAll(), 'name');

        $this->assertSame($registered, $listed);
        $this->assertCount(71, $listed);
    }

    /**
     * @dataProvider articleContentAliases
     */
    public function testUpdateArticleMapsContentAliasesToIntrotext(string $alias): void
    {
        $body = $this->capturePatchFromUpdateArticle([$alias => '<p>Hello</p>', 'title' => 'Updated']);

        $this->assertSame('<p>Hello</p>', $body['introtext']);
        $this->assertArrayNotHasKey($alias, $body);
        $this->assertSame('Updated', $body['title']);
    }

    /**
     * @dataProvider articleContentAliases
     */
    public function testUpdateArticleDoesNotOverwriteExistingIntrotextWithAlias(string $alias): void
    {
        $body = $this->capturePatchFromUpdateArticle([
            'introtext' => '<p>Canonical</p>',
            $alias => '<p>Alias</p>',
        ]);

        $this->assertSame('<p>Canonical</p>', $body['introtext']);
        $this->assertArrayNotHasKey($alias, $body);
    }

    public function testCreateArticleStripsContentAliasesAndDefaultsLanguage(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('post')->willReturnCallback(
            static function (string $path, array $jsonBody) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $jsonBody];

                return ['data' => ['id' => 1]];
            }
        );

        $service = $this->makeService(null, $rest);
        $response = $this->callTool($service, 'create_article', [
            'article' => [
                'title' => 'New article',
                'catid' => 2,
                'introtext' => '<p>Body</p>',
                'articletext' => '<p>Alias</p>',
                'text' => '<p>Alias</p>',
                'content' => '<p>Alias</p>',
            ],
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame('api/index.php/v1/content/articles', $captured['path']);
        $this->assertSame('<p>Body</p>', $captured['body']['introtext']);
        $this->assertSame('*', $captured['body']['language']);
        $this->assertArrayNotHasKey('articletext', $captured['body']);
        $this->assertArrayNotHasKey('text', $captured['body']);
        $this->assertArrayNotHasKey('content', $captured['body']);
    }

    public function testSearchArticlesSendsFiltersAsJoomlaFilterQueryParams(): void
    {
        $capturedQuery = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            static function (string $path, array $query = []) use (&$capturedQuery): array {
                $capturedQuery = ['path' => $path, 'query' => $query];

                return ['data' => []];
            }
        );

        $service = $this->makeService(null, $rest);
        $this->callTool($service, 'search_articles', [
            'search' => 'hello',
            'language' => 'en-GB',
            'catid' => 5,
            'state' => 1,
            'author' => 42,
            'featured' => 1,
            'limit' => 10,
            'offset' => 20,
        ]);

        $this->assertSame('api/index.php/v1/content/articles', $capturedQuery['path']);
        $this->assertSame('hello', $capturedQuery['query']['filter[search]']);
        $this->assertSame('en-GB', $capturedQuery['query']['filter[language]']);
        $this->assertSame(5, $capturedQuery['query']['filter[category]']);
        $this->assertSame(1, $capturedQuery['query']['filter[state]']);
        $this->assertSame(42, $capturedQuery['query']['filter[author]']);
        $this->assertSame(1, $capturedQuery['query']['filter[featured]']);
        $this->assertSame(10, $capturedQuery['query']['page[limit]']);
        $this->assertSame(20, $capturedQuery['query']['page[offset]']);
        $this->assertArrayNotHasKey('search', $capturedQuery['query']);
        $this->assertArrayNotHasKey('catid', $capturedQuery['query']);
        $this->assertArrayNotHasKey('author', $capturedQuery['query']);
    }

    public function testUpdateMenuItemSendsACompleteMergedPayload(): void
    {
        $patched = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn([
            'data' => [
                'id' => 12,
                'attributes' => [
                    'title' => 'Home',
                    'alias' => 'home',
                    'menutype' => 'mainmenu',
                    'type' => 'component',
                    'link' => 'index.php?option=com_content&view=article&id=1',
                    'parent_id' => 1,
                    'published' => 1,
                    'access' => 1,
                    'language' => '*',
                    'browserNav' => 0,
                    'home' => 1,
                    'note' => '',
                    'component_id' => 22,
                    'params' => ['menu_show' => 1],
                    'request' => ['option' => 'com_content'],
                    'template_style_id' => 0,
                    'menuordering' => 1,
                ],
            ],
        ]);
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $jsonBody) use (&$patched): array {
                $patched = ['path' => $path, 'body' => $jsonBody];

                return ['data' => ['id' => 12]];
            }
        );

        $service = $this->makeService(null, $rest);
        $this->callTool($service, 'update_menu_item', [
            'id' => 12,
            'menu_item' => ['parent_id' => 5],
        ]);

        $this->assertSame('api/index.php/v1/menus/site/items/12', $patched['path']);
        $this->assertSame('Home', $patched['body']['title']);
        $this->assertSame('mainmenu', $patched['body']['menutype']);
        $this->assertSame(5, $patched['body']['parent_id']);
        $this->assertSame(1, $patched['body']['menuordering']);
        $this->assertInstanceOf(\stdClass::class, $patched['body']['params']);
        $this->assertInstanceOf(\stdClass::class, $patched['body']['request']);
        $this->assertSame(1, $patched['body']['params']->menu_show);
        $this->assertSame('com_content', $patched['body']['request']->option);
    }

    /**
     * @dataProvider protectedCacheGroups
     */
    public function testClearCacheRejectsAnExplicitProtectedGroup(string $group): void
    {
        $this->installCacheEnvironment(['page', $group]);

        $response = $this->callTool($this->makeService(), 'clear_cache', [
            'group' => $group,
            'client' => 'site',
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame([], Cache::$cleaned);
        $this->assertSame([], $this->toolResult($response)['data']['cleared']['site']);
        $this->assertNull(AfterPurgeEvent::$lastArguments);
    }

    public function testClearCacheOmitsEmptySubjectWhenClearingAllGroups(): void
    {
        $this->installCacheEnvironment(['page', 'mcp_sse', 'com_mcpserver_ratelimit']);

        $response = $this->callTool($this->makeService(), 'clear_cache', [
            'client' => 'site',
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame(['page'], Cache::$cleaned);
        $this->assertSame(['page'], $this->toolResult($response)['data']['cleared']['site']);
        $this->assertSame('onAfterPurge', AfterPurgeEvent::$lastName);
        $this->assertSame([], AfterPurgeEvent::$lastArguments);
        $this->assertArrayNotHasKey('subject', AfterPurgeEvent::$lastArguments);
    }

    public function testClearCacheClearsAnExplicitUnprotectedGroup(): void
    {
        $this->installCacheEnvironment(['page', 'mcp_sse']);

        $response = $this->callTool($this->makeService(), 'clear_cache', [
            'group' => 'page',
            'client' => 'site',
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame(['page'], Cache::$cleaned);
        $this->assertSame(['page'], $this->toolResult($response)['data']['cleared']['site']);
        $this->assertSame(['subject' => 'page'], AfterPurgeEvent::$lastArguments);
    }

    public static function protectedCacheGroups(): array
    {
        return [
            'sse' => ['mcp_sse'],
            'rate limit' => ['com_mcpserver_ratelimit'],
        ];
    }

    public static function articleContentAliases(): array
    {
        return [
            'articletext' => ['articletext'],
            'text' => ['text'],
            'content' => ['content'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capturePatchFromUpdateArticle(array $article): array
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $jsonBody) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $jsonBody];

                return ['data' => ['id' => 7]];
            }
        );

        $service = $this->makeService(null, $rest);
        $response = $this->callTool($service, 'update_article', [
            'id' => 7,
            'article' => $article,
        ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame('api/index.php/v1/content/articles/7', $captured['path']);

        return $captured['body'];
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
    }

    private function makeService(
        ?ToolRegistry $registry = null,
        ?RestClient $rest = null,
        bool $resourcesEnabled = false,
        bool $promptsEnabled = false,
    ): RpcService {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        // PHPUnit mocks return false for unstubbed bool methods; stub these
        // explicitly so resource/prompt tests can opt in without affecting
        // existing tool tests.
        $policy->method('resourcesEnabled')->willReturn($resourcesEnabled);
        $policy->method('promptsEnabled')->willReturn($promptsEnabled);

        return new RpcService(
            $rest ?? $this->createRestMock(),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            $registry ?? new ToolRegistry(),
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
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * @param  list<string>  $availableGroups
     */
    private function installCacheEnvironment(array $availableGroups): void
    {
        Cache::reset();
        AfterPurgeEvent::reset();
        Factory::reset();

        Cache::$availableGroups = $availableGroups;

        $dispatcher = new class {
            public function dispatch(string $name, object $event): object
            {
                return $event;
            }
        };

        Factory::$application = new class ($dispatcher) {
            public function __construct(private readonly object $dispatcher)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function getDispatcher(): object
            {
                return $this->dispatcher;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function toolResult(array $response): array
    {
        $this->assertArrayHasKey('structuredContent', $response['result']);

        return $response['result']['structuredContent'];
    }

    public function testFailedToolCallIsReportedAsFailedNotOk(): void
    {
        // A tool that throws is returned as a JSON-RPC *success* envelope
        // carrying an MCP isError result, so $response['error'] is unset. Without
        // wasLastCallFailed() the caller audits the failure as 'ok' and writes a
        // Joomla Action Log "success" entry for a mutation that never happened.
        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willThrowException(new \RuntimeException('upstream exploded'));

        $service = $this->makeService(null, $rest);
        $response = $this->callTool($service, 'search_articles', ['search' => 'hello']);

        $this->assertArrayNotHasKey('error', $response, 'tool failures are tool results, not JSON-RPC errors');
        $this->assertTrue($service->wasLastCallFailed(), 'the failure must be visible to the audit caller');
        $this->assertFalse($service->wasLastCallBlocked(), 'a failure is not a policy block');
    }

    public function testSuccessfulToolCallIsNotMarkedFailed(): void
    {
        $service = $this->makeService();
        $service->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        $this->assertFalse($service->wasLastCallFailed());
    }

    public function testGovernedPrincipalWithoutAuthorizerIsRejectedAtConstruction(): void
    {
        // The ACL gate is skipped entirely when no authorizer is wired, so this
        // pairing must be impossible to construct rather than merely avoided.
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);

        $this->expectException(\LogicException::class);

        new RpcService(
            $this->createRestMock(),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry(),
            'joomla-mcp-server',
            100,
            new AuthenticatedPrincipal(1, 'selector16charsx', 2, 'Ada', 'api-token'),
            null
        );
    }
}
