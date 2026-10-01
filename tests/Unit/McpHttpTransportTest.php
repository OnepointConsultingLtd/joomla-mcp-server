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
use Joomla\Component\Mcpserver\Administrator\Service\McpHttpTransport;
use Joomla\Component\Mcpserver\Administrator\Service\McpProtocolError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class McpHttpTransportTest extends TestCase
{
    public function testHeadersFromServerCollectsMcpHeadersLowercased(): void
    {
        $headers = McpHttpTransport::headersFromServer([
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => 'search_articles',
            'HTTP_ACCEPT' => 'application/json',
            'REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame([
            'mcp-protocol-version' => '2026-07-28',
            'mcp-method' => 'tools/call',
            'mcp-name' => 'search_articles',
        ], $headers);
    }

    public function testModernRequestWithMatchingHeadersPasses(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate($this->toolCall('search_articles'), $this->headersFor('tools/call', 'search_articles'));
    }

    public function testLegacyRequestWithoutHeadersPasses(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], []);
    }

    public function testLegacyRequestWithLegacyHeaderPasses(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['mcp-protocol-version' => '2025-06-18']
        );
    }

    public function testInitializeIsExemptEvenWithAModernHeader(): void
    {
        // initialize always selects legacy semantics; a dual-era client may still
        // send its preferred header on it.
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']],
            ['mcp-protocol-version' => '2026-07-28']
        );
    }

    public function testIdLessRequestMethodIsRejected(): void
    {
        // Without an id this would be a notification — and a notification skips
        // header validation. Only notifications/* methods may arrive that way,
        // or a tools/call could slip past the headers a gateway routed on.
        try {
            McpHttpTransport::validate(
                ['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'delete_article']],
                ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'search_articles']
            );
            $this->fail('Expected the request to be rejected');
        } catch (McpProtocolError $error) {
            $this->assertSame(JsonRpc::INVALID_REQUEST, $error->jsonRpcCode);
            $this->assertSame(400, $error->httpStatus);
        }
    }

    public function testNotificationsAreExempt(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate(
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 3]],
            ['mcp-protocol-version' => '2026-07-28']
        );
    }

    /**
     * @param  array<string, mixed>   $request
     * @param  array<string, string>  $headers
     */
    #[DataProvider('rejectedRequests')]
    public function testRejectedRequests(array $request, array $headers, int $expectedCode, string $expectedMessage): void
    {
        try {
            McpHttpTransport::validate($request, $headers);
            $this->fail('Expected the request to be rejected');
        } catch (McpProtocolError $error) {
            $this->assertSame($expectedCode, $error->jsonRpcCode);
            $this->assertSame(400, $error->httpStatus);
            $this->assertStringContainsString($expectedMessage, $error->getMessage());
        }
    }

    public static function rejectedRequests(): array
    {
        $call = self::toolCallRequest('search_articles');
        $headers = self::headersForRequest('tools/call', 'search_articles');

        return [
            'unsupported header version on a legacy request' => [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['mcp-protocol-version' => '1999-01-01'],
                JsonRpc::UNSUPPORTED_PROTOCOL_VERSION,
                'Unsupported protocol version',
            ],
            // The body's version is the one in dispute, and it is the one the
            // client should be told the server cannot speak.
            'unsupported body version beats the header mismatch' => [
                self::withBodyVersion($call, '2027-01-01'),
                $headers,
                JsonRpc::UNSUPPORTED_PROTOCOL_VERSION,
                'Unsupported protocol version',
            ],
            'modern header without body metadata' => [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
                JsonRpc::INVALID_PARAMS,
                'io.modelcontextprotocol/protocolVersion',
            ],
            'missing protocol version header' => [
                $call,
                array_diff_key($headers, ['mcp-protocol-version' => true]),
                JsonRpc::HEADER_MISMATCH,
                'Missing MCP-Protocol-Version header',
            ],
            'protocol version header disagrees with body' => [
                self::withBodyVersion($call, '2026-07-28'),
                ['mcp-protocol-version' => '2025-11-25'] + $headers,
                JsonRpc::HEADER_MISMATCH,
                'MCP-Protocol-Version',
            ],
            'missing method header' => [
                $call,
                array_diff_key($headers, ['mcp-method' => true]),
                JsonRpc::HEADER_MISMATCH,
                'Missing Mcp-Method header',
            ],
            'method header disagrees with body' => [
                $call,
                ['mcp-method' => 'tools/list'] + $headers,
                JsonRpc::HEADER_MISMATCH,
                'Mcp-Method',
            ],
            'missing name header on tools/call' => [
                $call,
                array_diff_key($headers, ['mcp-name' => true]),
                JsonRpc::HEADER_MISMATCH,
                'Missing Mcp-Name header',
            ],
            'name header disagrees with body' => [
                $call,
                ['mcp-name' => 'delete_article'] + $headers,
                JsonRpc::HEADER_MISMATCH,
                'Mcp-Name',
            ],
            'name header for resources/read compares the uri' => [
                self::modernRequest('resources/read', ['uri' => 'joomla://article/5']),
                self::headersForRequest('resources/read', 'joomla://article/6'),
                JsonRpc::HEADER_MISMATCH,
                'Mcp-Name',
            ],
            'non-ascii header value' => [
                $call,
                ['mcp-name' => "search_articl\xC3\xA9"] + $headers,
                JsonRpc::HEADER_MISMATCH,
                'invalid characters',
            ],
            'malformed base64 sentinel' => [
                $call,
                ['mcp-name' => '=?base64?not*base64?='] + $headers,
                JsonRpc::HEADER_MISMATCH,
                'base64',
            ],
        ];
    }

    public function testHeaderlessDiscoveryProbeGetsALegacyMethodNotFound(): void
    {
        // A dual-era stdio client probes with server/discover and falls back to
        // initialize on anything but a modern error. Through a bridge that
        // predates 2026-07-28 the probe arrives without headers; a modern
        // -32020 there would stop the client instead of letting it fall back.
        try {
            McpHttpTransport::validate(self::modernRequest('server/discover'), []);
            $this->fail('Expected the probe to be answered as a legacy request');
        } catch (McpProtocolError $error) {
            $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $error->jsonRpcCode);
            $this->assertSame(200, $error->httpStatus);
            $this->assertStringContainsString('download it again', $error->getMessage());
        }
    }

    public function testBase64SentinelNameIsDecodedBeforeComparing(): void
    {
        $this->expectNotToPerformAssertions();

        $name = 'Grüße aus Köln';
        McpHttpTransport::validate(
            self::modernRequest('prompts/get', ['name' => $name]),
            ['mcp-name' => '=?base64?' . base64_encode($name) . '?='] + $this->headersFor('prompts/get', $name)
        );
    }

    public function testNameHeaderIsNotRequiredForMethodsWithoutAName(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validate(self::modernRequest('tools/list'), $this->headersFor('tools/list'));
    }

    public function testModernBatchIsRejected(): void
    {
        $batch = [self::modernRequest('tools/list'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']];

        try {
            McpHttpTransport::validateBatch($batch, []);
            $this->fail('Expected the batch to be rejected');
        } catch (McpProtocolError $error) {
            $this->assertSame(JsonRpc::INVALID_REQUEST, $error->jsonRpcCode);
            $this->assertSame(400, $error->httpStatus);
        }
    }

    public function testBatchUnderAModernHeaderIsRejected(): void
    {
        $this->expectException(McpProtocolError::class);

        McpHttpTransport::validateBatch(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']],
            ['mcp-protocol-version' => '2026-07-28']
        );
    }

    public function testBatchUnderAnUnsupportedHeaderVersionIsRejected(): void
    {
        try {
            McpHttpTransport::validateBatch(
                [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']],
                ['mcp-protocol-version' => '1999-01-01']
            );
            $this->fail('Expected the batch to be rejected');
        } catch (McpProtocolError $error) {
            $this->assertSame(JsonRpc::UNSUPPORTED_PROTOCOL_VERSION, $error->jsonRpcCode);
        }
    }

    public function testLegacyBatchIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validateBatch(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']],
            ['mcp-protocol-version' => '2025-03-26']
        );
    }

    #[DataProvider('origins')]
    public function testOriginPolicy(string $origin, array $allowed, bool $expected): void
    {
        $this->assertSame($expected, McpHttpTransport::isOriginAllowed($origin, $allowed));
    }

    public static function origins(): array
    {
        return [
            'allow-listed' => ['https://app.example.org', ['https://app.example.org'], true],
            'foreign' => ['https://evil.example.net', ['https://app.example.org'], false],
            // Under DNS rebinding the page's origin is also the Host the browser
            // sends, so "the site's own origin" as Joomla derives it (from Host)
            // proves nothing: only an explicit allow-list entry does.
            'the host the request was sent to' => ['http://evil.example.net', [], false],
            'allow-listed host, other scheme' => ['http://app.example.org', ['https://app.example.org'], false],
            'opaque origin' => ['null', [], false],
        ];
    }

    public function testNotificationStatusIs202ForHeaderAwareClients(): void
    {
        $this->assertSame(202, McpHttpTransport::notificationStatus(['mcp-protocol-version' => '2025-06-18']));
    }

    public function testNotificationStatusStays204ForClientsWithoutTheHeader(): void
    {
        // Bridges installed before 2026-07-28 support parse any non-204 body as
        // JSON; an empty 202 would make them emit an error frame per notification.
        $this->assertSame(204, McpHttpTransport::notificationStatus([]));
    }

    public function testResponseStatusPrefersTheServiceHint(): void
    {
        $this->assertSame(404, McpHttpTransport::responseStatus(404, JsonRpc::errorResponse(1, JsonRpc::METHOD_NOT_FOUND, 'x')));
    }

    public function testResponseStatusMapsLegacyErrorCodes(): void
    {
        $this->assertSame(401, McpHttpTransport::responseStatus(null, JsonRpc::errorResponse(1, JsonRpc::UNAUTHORIZED, 'x')));
        $this->assertSame(429, McpHttpTransport::responseStatus(null, JsonRpc::errorResponse(1, JsonRpc::RATE_LIMITED, 'x')));
        $this->assertSame(200, McpHttpTransport::responseStatus(null, JsonRpc::errorResponse(1, JsonRpc::METHOD_NOT_FOUND, 'x')));
        $this->assertSame(200, McpHttpTransport::responseStatus(null, JsonRpc::successResponse(1, [])));
    }

    #[DataProvider('auditStatuses')]
    public function testAuditStatus(array $response, int $httpStatus, string $okStatus, string $expected): void
    {
        $this->assertSame($expected, McpHttpTransport::auditStatus($response, $httpStatus, $okStatus));
    }

    public static function auditStatuses(): array
    {
        return [
            'success keeps the service verdict' => [JsonRpc::successResponse(1, []), 200, 'blocked', 'blocked'],
            'a method error' => [JsonRpc::errorResponse(1, JsonRpc::INVALID_PARAMS, 'Invalid cursor'), 200, 'ok', 'error'],
            'a removed method' => [JsonRpc::errorResponse(1, JsonRpc::METHOD_NOT_FOUND, 'x'), 404, 'ok', 'error'],
            // Rejected metadata never reached a method, like a header mismatch.
            'unusable request metadata' => [JsonRpc::errorResponse(1, JsonRpc::INVALID_PARAMS, 'x'), 400, 'ok', 'invalid_request'],
        ];
    }

    public function testSseFramesEmitOneMessageEventPerMessageInOrder(): void
    {
        $frames = McpHttpTransport::sseFrames([
            ['jsonrpc' => '2.0', 'method' => 'notifications/subscriptions/acknowledged', 'params' => ['notifications' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete']],
        ]);

        $this->assertSame(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/subscriptions/acknowledged\",\"params\":{\"notifications\":{}}}\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"resultType\":\"complete\"}}\n\n",
            $frames
        );
    }

    #[DataProvider('acceptedParamHeaders')]
    public function testMatchingParamHeadersPass(array $arguments, array $headers): void
    {
        $this->expectNotToPerformAssertions();

        McpHttpTransport::validateParamHeaders(self::modernRequest('tools/call', ['name' => 'get_article_by_id', 'arguments' => $arguments]), $headers, ['id' => 'Id']);
    }

    public static function acceptedParamHeaders(): array
    {
        return [
            'exact' => [['id' => 5], ['mcp-param-id' => '5']],
            'leading zero' => [['id' => 5], ['mcp-param-id' => '05']],
            'decimal spelling' => [['id' => 5], ['mcp-param-id' => '5.0']],
            'leading space' => [['id' => 5], ['mcp-param-id' => ' 5']],
            'numeric string argument' => [['id' => '5'], ['mcp-param-id' => '5']],
            'integral float argument' => [['id' => 5.0], ['mcp-param-id' => '5']],
            'base64 sentinel' => [['id' => 5], ['mcp-param-id' => '=?base64?NQ==?=']],
            'absent value, no header' => [[], []],
            'null value, no header' => [['id' => null], []],
        ];
    }

    #[DataProvider('rejectedParamHeaders')]
    public function testMismatchedParamHeadersAreRejected(array $arguments, array $headers, string $fragment): void
    {
        try {
            McpHttpTransport::validateParamHeaders(self::modernRequest('tools/call', ['name' => 'get_article_by_id', 'arguments' => $arguments]), $headers, ['id' => 'Id']);
            $this->fail('Expected a header mismatch');
        } catch (McpProtocolError $error) {
            $this->assertSame(JsonRpc::HEADER_MISMATCH, $error->jsonRpcCode);
            $this->assertSame(400, $error->httpStatus);
            $this->assertStringContainsString($fragment, $error->getMessage());
        }
    }

    public static function rejectedParamHeaders(): array
    {
        return [
            'missing header' => [['id' => 5], [], 'Missing Mcp-Param-Id'],
            'different number' => [['id' => 5], ['mcp-param-id' => '6'], 'Mcp-Param-Id'],
            'not a number' => [['id' => 5], ['mcp-param-id' => 'five'], 'Mcp-Param-Id'],
            'header for an absent value' => [[], ['mcp-param-id' => '5'], 'absent'],
            // Floats cannot tell these apart; the digits can.
            'beyond float precision' => [['id' => 9007199254740993], ['mcp-param-id' => '9007199254740992'], 'Mcp-Param-Id'],
            'exponent form' => [['id' => 10], ['mcp-param-id' => '1e1'], 'Mcp-Param-Id'],
            'invalid characters' => [['id' => 5], ['mcp-param-id' => "5\xC3\xA9"], 'invalid characters'],
        ];
    }

    public function testParamHeadersAreOnlyCheckedOnModernToolCalls(): void
    {
        $this->expectNotToPerformAssertions();

        // Legacy request: no _meta version.
        McpHttpTransport::validateParamHeaders(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'x', 'arguments' => ['id' => 5]]], [], ['id' => 'Id']);
        // Not a tools/call.
        McpHttpTransport::validateParamHeaders(self::modernRequest('tools/list'), [], ['id' => 'Id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCall(string $name): array
    {
        return self::toolCallRequest($name);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $method, ?string $name = null): array
    {
        return self::headersForRequest($method, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolCallRequest(string $name): array
    {
        return self::modernRequest('tools/call', ['name' => $name, 'arguments' => []]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function modernRequest(string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];

        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function withBodyVersion(array $request, string $version): array
    {
        $request['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = $version;

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private static function headersForRequest(string $method, ?string $name = null): array
    {
        $headers = ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method];
        if ($name !== null) {
            $headers['mcp-name'] = $name;
        }

        return $headers;
    }
}
