<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Component\Mcpserver\Administrator\Service\McpProtocol;
use Joomla\Component\Mcpserver\Administrator\Service\McpProtocolError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class McpProtocolTest extends TestCase
{
    public function testSupportedVersionsListModernFirstThenEveryLegacyRevision(): void
    {
        $this->assertSame(
            ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'],
            McpProtocol::supportedVersions()
        );
    }

    public function testLegacyNegotiationEchoesAKnownLegacyVersion(): void
    {
        $this->assertSame('2025-06-18', McpProtocol::negotiateLegacy('2025-06-18'));
    }

    #[DataProvider('versionsInitializeCannotNegotiate')]
    public function testLegacyNegotiationFallsBackToTheNewestLegacyVersion(mixed $requested): void
    {
        // initialize is the legacy handshake: it can never select the stateless
        // revision, which has no handshake to negotiate.
        $this->assertSame('2025-11-25', McpProtocol::negotiateLegacy($requested));
    }

    public static function versionsInitializeCannotNegotiate(): array
    {
        return [
            'modern' => ['2026-07-28'],
            'unknown' => ['1999-01-01'],
            'absent' => [null],
            'not a string' => [20250618],
        ];
    }

    public function testRequestWithoutMetaIsLegacy(): void
    {
        $this->assertNull(McpProtocol::contextFor($this->request('tools/list')));
    }

    public function testMetaWithoutProtocolVersionIsLegacy(): void
    {
        // Legacy clients already send _meta for progress tokens.
        $this->assertNull(McpProtocol::contextFor($this->request('tools/list', [
            '_meta' => ['progressToken' => 'abc'],
        ])));
    }

    public function testDeclaredLegacyVersionIsServedWithLegacySemantics(): void
    {
        $this->assertNull(McpProtocol::contextFor($this->request('tools/list', [
            '_meta' => $this->modernMeta(['io.modelcontextprotocol/protocolVersion' => '2025-11-25']),
        ])));
    }

    public function testInitializeIsAlwaysLegacyEvenWithModernMeta(): void
    {
        $this->assertNull(McpProtocol::contextFor($this->request('initialize', [
            '_meta' => $this->modernMeta(),
        ])));
    }

    public function testNotificationsAreNeverClassified(): void
    {
        $notification = $this->request('notifications/cancelled', ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        ]]);
        unset($notification['id']);

        $this->assertNull(McpProtocol::contextFor($notification));
    }

    public function testModernRequestYieldsContext(): void
    {
        $context = McpProtocol::contextFor($this->request('tools/list', [
            '_meta' => $this->modernMeta([
                'io.modelcontextprotocol/clientInfo' => ['name' => 'client', 'version' => '1.0'],
                'io.modelcontextprotocol/logLevel' => 'warning',
            ]),
        ]));

        $this->assertNotNull($context);
        $this->assertSame('2026-07-28', $context->protocolVersion);
        $this->assertSame([], $context->clientCapabilities);
        $this->assertSame(['name' => 'client', 'version' => '1.0'], $context->clientInfo);
        $this->assertSame('warning', $context->logLevel);
    }

    #[DataProvider('unsupportedVersions')]
    public function testUnsupportedVersionIsRejectedWithTheSupportedList(mixed $version, string $requested): void
    {
        $error = $this->protocolErrorFor($this->request('tools/list', [
            '_meta' => $this->modernMeta(['io.modelcontextprotocol/protocolVersion' => $version]),
        ]));

        $this->assertSame(JsonRpc::UNSUPPORTED_PROTOCOL_VERSION, $error->jsonRpcCode);
        $this->assertSame(400, $error->httpStatus);
        $this->assertSame(
            ['supported' => McpProtocol::supportedVersions(), 'requested' => $requested],
            $error->data
        );
    }

    public static function unsupportedVersions(): array
    {
        return [
            'unknown date' => ['1900-01-01', '1900-01-01'],
            'not a string' => [20260728, '20260728'],
        ];
    }

    #[DataProvider('invalidClientCapabilities')]
    public function testModernRequestRequiresClientCapabilitiesObject(array $meta): void
    {
        $error = $this->protocolErrorFor($this->request('tools/list', ['_meta' => $meta]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $error->jsonRpcCode);
        $this->assertSame(400, $error->httpStatus);
        $this->assertStringContainsString('clientCapabilities', $error->getMessage());
    }

    public static function invalidClientCapabilities(): array
    {
        return [
            'missing' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
            'a string' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => 'all']],
            'a JSON list' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => ['roots']]],
        ];
    }

    public function testClientInfoMustBeAnObjectWhenPresent(): void
    {
        $error = $this->protocolErrorFor($this->request('tools/list', [
            '_meta' => $this->modernMeta(['io.modelcontextprotocol/clientInfo' => 'curl']),
        ]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $error->jsonRpcCode);
        $this->assertStringContainsString('clientInfo', $error->getMessage());
    }

    public function testUnknownLogLevelIsInvalidParams(): void
    {
        $error = $this->protocolErrorFor($this->request('tools/list', [
            '_meta' => $this->modernMeta(['io.modelcontextprotocol/logLevel' => 'verbose']),
        ]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $error->jsonRpcCode);
        $this->assertStringContainsString('logLevel', $error->getMessage());
    }

    public function testModernRequestIdMustNotBeNull(): void
    {
        $request = $this->request('tools/list', ['_meta' => $this->modernMeta()]);
        $request['id'] = null;

        $error = $this->protocolErrorFor($request);

        $this->assertSame(JsonRpc::INVALID_REQUEST, $error->jsonRpcCode);
        $this->assertSame(400, $error->httpStatus);
    }

    public function testProtocolErrorRendersAsJsonRpcErrorWithData(): void
    {
        $error = McpProtocol::unsupportedVersion('1900-01-01');

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => [
                'code' => JsonRpc::UNSUPPORTED_PROTOCOL_VERSION,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => McpProtocol::supportedVersions(), 'requested' => '1900-01-01'],
            ],
        ], $error->toResponse(7));
    }

    public function testIsModernRequestReadsTheDeclaredVersion(): void
    {
        $this->assertTrue(McpProtocol::isModernRequest($this->request('tools/list', ['_meta' => $this->modernMeta()])));
        $this->assertFalse(McpProtocol::isModernRequest($this->request('tools/list')));
        $this->assertFalse(McpProtocol::isModernRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'x', 'params' => 'bogus']));
    }

    public function testCompleteResultAddsResultTypeAndServerInfo(): void
    {
        $result = McpProtocol::completeResult('tools/call', ['content' => []], $this->serverInfo(), 60000);

        $this->assertSame('complete', $result['resultType']);
        $this->assertSame($this->serverInfo(), $result['_meta']['io.modelcontextprotocol/serverInfo']);
        $this->assertArrayNotHasKey('ttlMs', $result, 'tools/call is not cacheable');
        $this->assertArrayNotHasKey('cacheScope', $result);
    }

    public function testCompleteResultMergesIntoExistingMeta(): void
    {
        $result = McpProtocol::completeResult(
            'subscriptions/listen',
            ['_meta' => ['io.modelcontextprotocol/subscriptionId' => 4]],
            $this->serverInfo(),
            60000
        );

        $this->assertSame(4, $result['_meta']['io.modelcontextprotocol/subscriptionId']);
        $this->assertSame($this->serverInfo(), $result['_meta']['io.modelcontextprotocol/serverInfo']);
    }

    #[DataProvider('cacheableMethods')]
    public function testCompleteResultAddsCachingHints(string $method, string $scope, int $ttlMs): void
    {
        $result = McpProtocol::completeResult($method, [], $this->serverInfo(), 45000);

        $this->assertSame($scope, $result['cacheScope']);
        $this->assertSame($ttlMs, $result['ttlMs']);
    }

    public static function cacheableMethods(): array
    {
        return [
            'discover' => ['server/discover', 'public', McpProtocol::CATALOGUE_TTL_MS],
            'tools' => ['tools/list', 'public', McpProtocol::CATALOGUE_TTL_MS],
            'prompts' => ['prompts/list', 'public', McpProtocol::CATALOGUE_TTL_MS],
            'templates' => ['resources/templates/list', 'public', McpProtocol::CATALOGUE_TTL_MS],
            // Read with the caller's own API token in Governed Mode, so the
            // content can differ per user and must never sit in a shared cache.
            'resources' => ['resources/list', 'private', 45000],
            'read' => ['resources/read', 'private', 45000],
        ];
    }

    public function testCompleteResultNeverEmitsANegativeTtl(): void
    {
        $result = McpProtocol::completeResult('resources/read', [], $this->serverInfo(), -5);

        $this->assertSame(0, $result['ttlMs']);
    }

    public function testCompleteResultKeepsAServerInfoTheResultAlreadyCarries(): void
    {
        $full = ['name' => 'joomla-mcp-server', 'version' => '1.9.0', 'title' => 'MCP Server for Joomla'];

        $result = McpProtocol::completeResult(
            'server/discover',
            ['_meta' => ['io.modelcontextprotocol/serverInfo' => $full]],
            $this->serverInfo(),
            0
        );

        $this->assertSame($full, $result['_meta']['io.modelcontextprotocol/serverInfo']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function modernMeta(array $overrides = []): array
    {
        return array_merge([
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ], $overrides);
    }

    /**
     * @return array{name: string, version: string}
     */
    private function serverInfo(): array
    {
        return ['name' => 'joomla-mcp-server', 'version' => '1.9.0'];
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function protocolErrorFor(array $request): McpProtocolError
    {
        try {
            McpProtocol::contextFor($request);
        } catch (McpProtocolError $error) {
            return $error;
        }

        $this->fail('Expected an McpProtocolError');
    }
}
