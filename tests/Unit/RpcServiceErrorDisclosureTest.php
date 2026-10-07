<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Failure messages returned to MCP clients must not disclose internals:
 * upstream URLs, cURL diagnostics, SQL, file paths. Guzzle's transfer
 * exceptions, Joomla's database and filesystem exceptions and PDOException all
 * extend \RuntimeException — the class this component uses for deliberate,
 * caller-safe messages — so "is a RuntimeException" cannot mean "safe to show".
 */
class RpcServiceErrorDisclosureTest extends TestCase
{
    private const API_BASE = 'http://10.0.0.5:8080';

    private const ARTICLE_URL = self::API_BASE . '/api/index.php/v1/content/articles/5';

    /** Fragments that must never reach a client. */
    private const INTERNALS = ['10.0.0.5', 'cURL', 'curl.haxx.se', '/api/index.php', 'SQLSTATE', 'jos_users', '/var/www'];

    public function testConnectionFailureDoesNotDiscloseTheUpstreamUrlOrCurlError(): void
    {
        $rest = $this->restThrowingOnGet($this->connectFailure());
        $service = $this->makeService($rest);

        $text = $this->toolErrorText($service->handle($this->callTool('get_article_by_id', ['id' => 5])));

        $this->assertNoInternals($text);
        $this->assertSame(
            'The Joomla Web Services API could not be reached. Check the Base URL and Resolve Host To IP settings in MCP Server options.',
            $text
        );
        $this->assertTrue($service->wasLastCallFailed());
    }

    public function testApiErrorKeepsJoomlasOwnMessageButNotTheUrl(): void
    {
        // What Guzzle's http_errors middleware throws: its message embeds the
        // request URL and a summary of the body.
        $error = RequestException::create(
            new Request('GET', self::ARTICLE_URL),
            new Response(403, ['Content-Type' => 'application/vnd.api+json'], '{"errors":[{"title":"You are not authorised to view this resource.","code":403}]}')
        );
        $this->assertStringContainsString(self::ARTICLE_URL, $error->getMessage(), 'precondition: the raw message discloses the URL');

        $text = $this->toolErrorText($this->makeService($this->restThrowingOnGet($error))->handle($this->callTool('get_article_by_id', ['id' => 5])));

        $this->assertNoInternals($text);
        $this->assertSame('The Joomla Web Services API returned HTTP 403: You are not authorised to view this resource.', $text);
    }

    public function testApiErrorWithSeveralJoomlaErrorsJoinsTheirTitles(): void
    {
        $error = RequestException::create(
            new Request('GET', self::ARTICLE_URL),
            new Response(400, [], '{"errors":[{"title":"Field required: Title"},{"title":"Field required: Category"}]}')
        );

        $text = $this->toolErrorText($this->makeService($this->restThrowingOnGet($error))->handle($this->callTool('get_article_by_id', ['id' => 5])));

        $this->assertSame('The Joomla Web Services API returned HTTP 400: Field required: Title; Field required: Category', $text);
    }

    #[DataProvider('bodiesWithoutJoomlaErrors')]
    public function testApiErrorWithoutJoomlaErrorsReportsOnlyTheStatus(string $body): void
    {
        $error = RequestException::create(new Request('GET', self::ARTICLE_URL), new Response(500, [], $body));

        $text = $this->toolErrorText($this->makeService($this->restThrowingOnGet($error))->handle($this->callTool('get_article_by_id', ['id' => 5])));

        $this->assertNoInternals($text);
        $this->assertSame('The Joomla Web Services API returned HTTP 500', $text);
    }

    public static function bodiesWithoutJoomlaErrors(): array
    {
        return [
            'a PHP fatal error page' => ['<b>Fatal error</b>: Uncaught PDOException: SQLSTATE[42S02] in /var/www/libraries/src/Table.php'],
            'JSON without errors' => ['{"message":"SQLSTATE[HY000] jos_users"}'],
            'an errors entry without a title' => ['{"errors":[{"code":500}]}'],
            'empty' => [''],
        ];
    }

    public function testFailureFetchingACallerSuppliedUrlIsNotAttributedToTheApi(): void
    {
        $rest = $this->createRestMock();
        $rest->method('fetchUrlContent')->willThrowException(new ConnectException(
            'cURL error 6: Could not resolve host: files.example.org (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://files.example.org/logo.png',
            new Request('GET', 'https://files.example.org/logo.png')
        ));

        $text = $this->toolErrorText($this->makeService($rest)->handle($this->callTool('upload_media', [
            'path' => 'banners/logo.png',
            'source_url' => 'https://files.example.org/logo.png',
        ])));

        $this->assertNoInternals($text);
        $this->assertSame('The requested URL could not be reached', $text);
    }

    #[DataProvider('libraryExceptions')]
    public function testLibraryRuntimeExceptionsAreNotDisclosed(\Throwable $exception): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'query_something',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]);
        $registry->setExecutor('query_something', static fn () => throw $exception);

        $text = $this->toolErrorText($this->makeService(null, $registry)->handle($this->callTool('query_something', [])));

        $this->assertSame('Tool execution failed due to an internal error', $text);
    }

    public static function libraryExceptions(): array
    {
        return [
            // Joomla\Database\Exception\ExecutionFailureException, among others, has this shape.
            'a database driver exception' => [new class ("SQLSTATE[42S02]: Base table or view not found: SELECT * FROM jos_users") extends \RuntimeException {}],
            'PDOException' => [new \PDOException('SQLSTATE[HY000] [2002] Connection refused')],
            'a filesystem exception' => [new \UnexpectedValueException('Cannot open /var/www/html/configuration.php')],
        ];
    }

    #[DataProvider('ownExceptions')]
    public function testThisComponentsOwnMessagesStillReachTheCaller(\Throwable $exception): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'do_something',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]);
        $registry->setExecutor('do_something', static fn () => throw $exception);

        $text = $this->toolErrorText($this->makeService(null, $registry)->handle($this->callTool('do_something', [])));

        $this->assertSame($exception->getMessage(), $text);
    }

    public static function ownExceptions(): array
    {
        return [
            'InvalidArgumentException' => [new \InvalidArgumentException('Provide exactly one of article_id or menu_item_id')],
            'RuntimeException' => [new \RuntimeException('Extension is protected and cannot be uninstalled')],
        ];
    }

    public function testResourceReadFailureDoesNotDiscloseTheUpstreamUrl(): void
    {
        $response = $this->makeService($this->restThrowingOnGet($this->connectFailure()), null, resourcesEnabled: true)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'joomla://article/5'],
        ]);

        $this->assertSame(JsonRpc::INTERNAL_ERROR, $response['error']['code']);
        $this->assertNoInternals($response['error']['message']);
        $this->assertStringStartsWith('The Joomla Web Services API could not be reached', $response['error']['message']);
    }

    public function testPromptBuildFailureDoesNotDiscloseTheUpstreamUrl(): void
    {
        $response = $this->makeService($this->restThrowingOnGet($this->connectFailure()), null, promptsEnabled: true)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => ['name' => 'seo-audit-article', 'arguments' => ['article_id' => '5']],
        ]);

        $this->assertSame(JsonRpc::INTERNAL_ERROR, $response['error']['code']);
        $this->assertNoInternals($response['error']['message']);
        $this->assertStringStartsWith('The Joomla Web Services API could not be reached', $response['error']['message']);
    }

    private function connectFailure(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received '
            . '(see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::ARTICLE_URL,
            new Request('GET', self::ARTICLE_URL)
        );
    }

    private function assertNoInternals(string $text): void
    {
        foreach (self::INTERNALS as $fragment) {
            $this->assertStringNotContainsString($fragment, $text);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toolErrorText(array $response): string
    {
        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError'] ?? false, 'expected a tool execution error');

        return $response['result']['content'][0]['text'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
    }

    /**
     * @return RestClient&MockObject
     */
    private function restThrowingOnGet(\Throwable $exception): RestClient
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willThrowException($exception);

        return $rest;
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        $rest = $this->createMock(RestClient::class);
        $rest->method('getBaseUrl')->willReturn(self::API_BASE);

        return $rest;
    }

    private function makeService(
        ?RestClient $rest = null,
        ?ToolRegistry $registry = null,
        bool $resourcesEnabled = false,
        bool $promptsEnabled = false,
    ): RpcService {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
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
}
