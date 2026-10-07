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
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Component\Mcpserver\Administrator\Service\McpProtocol;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The stateless 2026-07-28 revision: per-request _meta, server/discover,
 * resultType and caching hints, removed methods, subscriptions/listen.
 */
class RpcServiceModernProtocolTest extends TestCase
{
    public function testDiscoverAdvertisesVersionsCapabilitiesAndIdentity(): void
    {
        $response = $this->makeService()->handle($this->modern('server/discover'));
        $result = $response['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(McpProtocol::supportedVersions(), $result['supportedVersions']);
        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertIsString($result['instructions']);
        $this->assertSame('joomla-mcp-server', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertIsString($result['_meta']['io.modelcontextprotocol/serverInfo']['version']);
        $this->assertSame(McpProtocol::CATALOGUE_TTL_MS, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
    }

    public function testDiscoverCapabilitiesFollowPolicy(): void
    {
        $response = $this->makeService(resourcesEnabled: true, promptsEnabled: true)->handle($this->modern('server/discover'));

        $this->assertSame(
            '{"tools":{"listChanged":false},"resources":{"subscribe":false,"listChanged":false},"prompts":{"listChanged":false},"completions":{}}',
            json_encode($response['result']['capabilities'])
        );
    }

    public function testDiscoverWithoutModernMetaIsRejectedAsMalformed(): void
    {
        $service = $this->makeService();
        $response = $service->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover']);

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame(400, $service->getLastHttpStatus());
    }

    public function testModernToolsListCarriesResultTypeCachingHintsAndStableOrder(): void
    {
        $registry = new ToolRegistry();
        $service = $this->makeService(registry: $registry);

        $first = $service->handle($this->modern('tools/list'));
        $second = $service->handle($this->modern('tools/list'));

        $this->assertSame('complete', $first['result']['resultType']);
        $this->assertSame('public', $first['result']['cacheScope']);
        $this->assertSame(McpProtocol::CATALOGUE_TTL_MS, $first['result']['ttlMs']);
        $this->assertArrayHasKey('io.modelcontextprotocol/serverInfo', $first['result']['_meta']);
        $this->assertSame(array_column($registry->getAll(), 'name'), array_column($first['result']['tools'], 'name'));
        $this->assertSame($first, $second, 'tools/list must be deterministic so clients can cache it');
    }

    public function testModernResourceReadIsPrivateAndUsesTheCacheTtl(): void
    {
        $rest = $this->createRestMock();
        // No top-level data.id, so injectRawArticleContent skips the database.
        $rest->method('get')->willReturn(['data' => ['attributes' => ['introtext' => '<p>Hi</p>', 'fulltext' => '']]]);

        $response = $this->makeService($rest, resourcesEnabled: true, cacheTtlSeconds: 90)->handle(
            $this->modern('resources/read', ['uri' => 'joomla://article/5'])
        );

        $this->assertSame('private', $response['result']['cacheScope']);
        $this->assertSame(90000, $response['result']['ttlMs']);
        $this->assertSame('<p>Hi</p>', $response['result']['contents'][0]['text']);
    }

    #[DataProvider('methodsNotInTheModernRevision')]
    public function testMethodsOutsideTheModernRevisionAreNotFound(string $method): void
    {
        $service = $this->makeService();
        $response = $service->handle($this->modern($method));

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
        $this->assertSame(404, $service->getLastHttpStatus());
    }

    public static function methodsNotInTheModernRevision(): array
    {
        return [
            'ping' => ['ping'],
            'logging/setLevel' => ['logging/setLevel'],
            'resources/subscribe' => ['resources/subscribe'],
            'completion/complete' => ['completion/complete'],
            'a notification sent as a request' => ['notifications/initialized'],
            'the legacy capabilities alias' => ['capabilities'],
        ];
    }

    public function testPolicyDisabledResourceReadIsNotFound(): void
    {
        $service = $this->makeService();
        $response = $service->handle($this->modern('resources/read', ['uri' => 'joomla://article/5']));

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
        $this->assertSame(404, $service->getLastHttpStatus());
    }

    #[DataProvider('capabilityGatedListMethods')]
    public function testListMethodsOfAnUnadvertisedCapabilityAreNotFound(string $method): void
    {
        // Legacy clients keep their empty lists; a modern client gets the
        // MethodNotFound the schema prescribes for an unadvertised capability.
        $service = $this->makeService();
        $response = $service->handle($this->modern($method));

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
        $this->assertSame(404, $service->getLastHttpStatus());
    }

    public static function capabilityGatedListMethods(): array
    {
        return [
            'resources/list' => ['resources/list'],
            'resources/templates/list' => ['resources/templates/list'],
            'prompts/list' => ['prompts/list'],
        ];
    }

    public function testIdLessRequestMethodIsNeverExecuted(): void
    {
        // An id-less message is a notification, which gets no response — so a
        // tools/call sent that way must not run the tool unseen.
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('get');
        $service = $this->makeService($rest);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'get_article_by_id', 'arguments' => ['id' => 5]],
        ]);

        $this->assertNull($response);
        $this->assertFalse($service->wasLastCallFailed());
    }

    public function testOrdinaryMethodErrorsKeepTheDefaultHttpStatus(): void
    {
        $service = $this->makeService();
        $response = $service->handle($this->modern('tools/list', ['cursor' => 'not-a-cursor']));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertNull($service->getLastHttpStatus());
    }

    public function testSubscriptionsListenAcknowledgesAnEmptyFilterAndEndsGracefully(): void
    {
        $service = $this->makeService();
        $request = $this->modern('subscriptions/listen', ['notifications' => ['toolsListChanged' => true]]);
        $request['id'] = 9;

        $response = $service->handle($request);
        $notifications = $service->takeStreamNotifications();

        $this->assertSame('complete', $response['result']['resultType']);
        $this->assertSame(9, $response['result']['_meta']['io.modelcontextprotocol/subscriptionId']);
        $this->assertCount(1, $notifications);
        $this->assertSame('notifications/subscriptions/acknowledged', $notifications[0]['method']);
        $this->assertSame(9, $notifications[0]['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
        // The server honours none of the requested types, and the filter must
        // reach the wire as a JSON object, not the [] an empty PHP array becomes.
        $this->assertStringContainsString('"notifications":{}', (string) json_encode($notifications[0]));
        $this->assertSame([], $service->takeStreamNotifications(), 'taking the notifications clears them');
    }

    public function testSubscriptionsListenRequiresANotificationFilter(): void
    {
        $response = $this->makeService()->handle($this->modern('subscriptions/listen'));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
    }

    public function testSubscriptionsListenIsNotALegacyMethod(): void
    {
        $response = $this->makeService()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'subscriptions/listen', 'params' => ['notifications' => []]]);

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
    }

    public function testModernToolCallResultsCarryResultType(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => ['attributes' => ['title' => 'Hello']]]);
        $service = $this->makeService($rest);

        $success = $service->handle($this->modern('tools/call', ['name' => 'get_article_by_id', 'arguments' => ['id' => 5]]));
        $failure = $service->handle($this->modern('tools/call', ['name' => 'get_article_by_id', 'arguments' => []]));

        $this->assertSame('complete', $success['result']['resultType']);
        $this->assertSame(['data' => ['attributes' => ['title' => 'Hello']]], $success['result']['structuredContent']);
        $this->assertArrayNotHasKey('ttlMs', $success['result'], 'tools/call results are not cacheable');
        $this->assertSame('complete', $failure['result']['resultType']);
        $this->assertTrue($failure['result']['isError']);
    }

    public function testModernStructuredContentMayBeAList(): void
    {
        $registry = $this->registryWithTool('list_things', static fn (): array => ['a', 'b']);
        $response = $this->makeService(registry: $registry)->handle(
            $this->modern('tools/call', ['name' => 'list_things', 'arguments' => []])
        );

        $this->assertSame(['a', 'b'], $response['result']['structuredContent']);
    }

    public function testDeclaredLegacyVersionIsServedWithLegacySemantics(): void
    {
        $request = $this->modern('tools/list');
        $request['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2025-11-25';

        $response = $this->makeService()->handle($request);

        $this->assertArrayNotHasKey('resultType', $response['result']);
        $this->assertArrayNotHasKey('ttlMs', $response['result']);
    }

    public function testUnsupportedVersionIsRejectedWithHttp400(): void
    {
        $request = $this->modern('tools/list');
        $request['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '1900-01-01';
        $service = $this->makeService();

        $response = $service->handle($request);

        $this->assertSame(JsonRpc::UNSUPPORTED_PROTOCOL_VERSION, $response['error']['code']);
        $this->assertSame('1900-01-01', $response['error']['data']['requested']);
        $this->assertSame(400, $service->getLastHttpStatus());
    }

    public function testMissingClientCapabilitiesIsRejectedWithHttp400(): void
    {
        $request = $this->modern('tools/list');
        unset($request['params']['_meta']['io.modelcontextprotocol/clientCapabilities']);
        $service = $this->makeService();

        $response = $service->handle($request);

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame(400, $service->getLastHttpStatus());
    }

    public function testStatusHintIsResetBetweenRequests(): void
    {
        $service = $this->makeService();
        $service->handle($this->modern('ping'));
        $service->handle($this->modern('tools/list'));

        $this->assertNull($service->getLastHttpStatus());
    }

    #[DataProvider('initializeVersions')]
    public function testInitializeNegotiatesLegacyVersionsOnly(string $requested, string $negotiated): void
    {
        $response = $this->makeService()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => $requested, 'capabilities' => []],
        ]);

        $this->assertSame($negotiated, $response['result']['protocolVersion']);
        $this->assertArrayNotHasKey('resultType', $response['result']);
    }

    public static function initializeVersions(): array
    {
        return [
            'newest legacy' => ['2025-11-25', '2025-11-25'],
            'older legacy' => ['2025-03-26', '2025-03-26'],
            'modern falls back' => ['2026-07-28', '2025-11-25'],
            'unknown falls back' => ['2030-01-01', '2025-11-25'],
        ];
    }

    public function testModernNotificationIsAcceptedWithoutAStatusHint(): void
    {
        $service = $this->makeService();
        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 3, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]);

        $this->assertNull($response);
        $this->assertNull($service->getLastHttpStatus());
    }

    public function testClientResponseObjectIsAcceptedSilently(): void
    {
        $this->assertNull($this->makeService()->handle(['jsonrpc' => '2.0', 'id' => 3, 'result' => []]));
    }

    #[DataProvider('nonObjectParams')]
    public function testNonObjectParamsAreInvalidParams(mixed $params): void
    {
        $response = $this->makeService()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => $params]);

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
    }

    public static function nonObjectParams(): array
    {
        return [
            'a string' => ['all'],
            'a list' => [[1, 2]],
        ];
    }

    public function testLegacyNotificationSentAsARequestGetsAnEmptyObjectResult(): void
    {
        $response = $this->makeService()->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'notifications/initialized']);

        $this->assertSame('{"jsonrpc":"2.0","id":4,"result":{}}', json_encode($response));
    }

    public function testDiscoverCarriesTheFullServerIdentity(): void
    {
        $info = $this->makeService()->handle($this->modern('server/discover'))['result']['_meta']['io.modelcontextprotocol/serverInfo'];

        $this->assertSame('joomla-mcp-server', $info['name']);
        $this->assertSame('MCP Server for Joomla', $info['title']);
        $this->assertSame('https://github.com/OnepointConsultingLtd/joomla-mcp-server', $info['websiteUrl']);
        $this->assertIsString($info['description']);
        $this->assertSame(
            [['src' => 'https://example.test/components/com_mcpserver/icon.png', 'mimeType' => 'image/png', 'sizes' => ['64x64']]],
            $info['icons']
        );
    }

    public function testInitializeCarriesTheFullServerIdentity(): void
    {
        $response = $this->makeService()->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => []],
        ]);

        $this->assertSame('MCP Server for Joomla', $response['result']['serverInfo']['title']);
        $this->assertArrayHasKey('icons', $response['result']['serverInfo']);
    }

    public function testOrdinaryResultsCarryOnlyNameAndVersion(): void
    {
        $info = $this->makeService()->handle($this->modern('tools/list'))['result']['_meta']['io.modelcontextprotocol/serverInfo'];

        $this->assertSame(['name', 'version'], array_keys($info));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function modern(string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
            'io.modelcontextprotocol/clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ];

        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    private function registryWithTool(string $name, callable $executor): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => $name,
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]);
        $registry->setExecutor($name, $executor);

        return $registry;
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
    }

    private function makeService(
        ?RestClient $rest = null,
        bool $resourcesEnabled = false,
        bool $promptsEnabled = false,
        ?ToolRegistry $registry = null,
        int $cacheTtlSeconds = 60,
    ): RpcService {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        $policy->method('resourcesEnabled')->willReturn($resourcesEnabled);
        $policy->method('promptsEnabled')->willReturn($promptsEnabled);

        return new RpcService(
            $rest ?? $this->createRestMock(),
            new CacheService(new SimpleArrayCache(), $cacheTtlSeconds),
            $policy,
            $this->createMock(LoggerInterface::class),
            $registry ?? new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }
}
