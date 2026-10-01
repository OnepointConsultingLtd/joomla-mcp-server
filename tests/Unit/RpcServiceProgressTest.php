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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RpcServiceProgressTest extends TestCase
{
    /** API calls the paged fake has served. */
    private int $gets = 0;

    public function testPagedFetchesReportProgressFirstAndLast(): void
    {
        $sink = new RecordingProgressSink();
        $service = $this->makeService($this->pagedRest(3));
        // A frozen clock: every point after the first falls in the throttle window,
        // so only the first and the final (progress == total) notification go out.
        $service->setProgressSink($sink, static fn (): float => 0.0);

        $response = $service->handle($this->callTool('list_custom_modules', [], 'tok'));

        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame(
            [['progressToken' => 'tok', 'progress' => 1.0, 'total' => 3.0, 'message' => 'Fetched page 1 of 3'],
             ['progressToken' => 'tok', 'progress' => 3.0, 'total' => 3.0, 'message' => 'Fetched page 3 of 3']],
            array_column($sink->sent, 'params')
        );
    }

    public function testTheLastPageIsReportedEvenWithoutAPageCount(): void
    {
        $sink = new RecordingProgressSink();
        $service = $this->makeService($this->pagedRest(3, withTotal: false));
        $service->setProgressSink($sink, static fn (): float => 0.0);

        $service->handle($this->callTool('list_custom_modules', [], 'tok'));

        $this->assertSame([1.0, 3.0], array_column(array_column($sink->sent, 'params'), 'progress'), 'first page, then the last page with data');
    }

    public function testADisconnectStopsTheWorkAndMarksTheCallFailed(): void
    {
        $service = $this->makeService($this->pagedRest(3));
        $service->setProgressSink(new RecordingProgressSink(abortAfterFirst: true));

        $response = $service->handle($this->callTool('list_custom_modules', [], 7));

        $this->assertSame(1, $this->gets, 'paging stops at the first progress point after the client left');
        $this->assertSame('Request cancelled by the client', $response['error']['message']);
        $this->assertSame(499, $service->getLastHttpStatus());
        $this->assertTrue($service->wasLastCallFailed());
    }

    public function testInstallIsCancelledBeforeAnythingIsInstalled(): void
    {
        $sink = new RecordingProgressSink(abortAfterFirst: true);
        $service = $this->makeService($this->createMock(RestClient::class));
        $service->setProgressSink($sink);

        // A ZIP signature is enough to pass the decode; reaching tmp_path or the
        // installer stubs would fail with a different message.
        $response = $service->handle($this->callTool('install_extension', ['content' => base64_encode("PK\x03\x04")], 'tok'));

        $this->assertSame('Request cancelled by the client', $response['error']['message']);
        $this->assertSame('Package received', $sink->sent[0]['params']['message']);
    }

    #[DataProvider('unusableTokens')]
    public function testAnUnusableTokenMeansNoProgressAndANormalResult(mixed $token): void
    {
        $sink = new RecordingProgressSink();
        $service = $this->makeService($this->pagedRest(2));
        $service->setProgressSink($sink);

        $response = $service->handle($this->callTool('list_custom_modules', [], $token));

        $this->assertArrayNotHasKey('error', $response);
        $this->assertArrayNotHasKey('isError', $response['result']);
        $this->assertSame([], $sink->sent);
    }

    public static function unusableTokens(): array
    {
        return ['array' => [['x']], 'boolean' => [true], 'float' => [1.5], 'absent' => [null]];
    }

    public function testWithoutASinkATokenIsHarmless(): void
    {
        $response = $this->makeService($this->pagedRest(2))->handle($this->callTool('list_custom_modules', [], 'tok'));

        $this->assertArrayNotHasKey('error', $response);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments, mixed $token): array
    {
        $params = ['name' => $name, 'arguments' => $arguments];
        if ($token !== null) {
            $params['_meta'] = ['progressToken' => $token];
        }

        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params];
    }

    /**
     * Full pages of 100 custom modules, the API reporting $pages pages in all.
     *
     * @return RestClient&MockObject
     */
    private function pagedRest(int $pages, bool $withTotal = true): RestClient
    {
        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willReturnCallback(function (string $path, array $query = []) use ($pages, $withTotal): array {
            $this->gets++;
            $page = intdiv((int) ($query['page[offset]'] ?? 0), 100);
            $meta = $withTotal ? ['total-pages' => $pages] : [];
            if ($page >= $pages) {
                return ['data' => [], 'meta' => $meta];
            }

            return [
                'data' => array_map(
                    static fn (int $n): array => ['type' => 'modules', 'id' => $page * 100 + $n, 'attributes' => ['module' => 'mod_custom']],
                    range(1, 100)
                ),
                'meta' => $meta,
            ];
        });

        return $rest;
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
