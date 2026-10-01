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
use GuzzleHttp\Psr7\Request;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RpcServiceCompletionsTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    public function testCategoryCompletesFromPublishedCategoryTitles(): void
    {
        $completion = $this->complete(['type' => 'ref/prompt', 'name' => 'draft-article'], 'category', 'gar');

        $this->assertSame(['values' => ['Garden', 'Gardening tips', 'Kitchen Garden'], 'total' => 3, 'hasMore' => false], $completion);
        $this->assertSame(1, $this->calls[0][1]['filter[published]'] ?? null, 'only published categories are offered');
    }

    public function testArticleIdCompletesNumericPrefixesNewestFirst(): void
    {
        $completion = $this->complete(['type' => 'ref/prompt', 'name' => 'seo-audit-article'], 'article_id', '12');

        $this->assertSame(['1200', '120', '12'], $completion['values']);
    }

    public function testArticleIdTextIsATitleSearchAnsweredWithIds(): void
    {
        $completion = $this->complete(['type' => 'ref/prompt', 'name' => 'translate-article'], 'article_id', 'garden');

        $this->assertSame(['9', '7'], $completion['values']);
        $searches = array_values(array_filter($this->calls, static fn (array $call): bool => isset($call[1]['filter[search]'])));
        $this->assertSame('garden', $searches[0][1]['filter[search]']);
    }

    public function testTargetLanguageCompletesPublishedLanguageCodes(): void
    {
        $completion = $this->complete(['type' => 'ref/prompt', 'name' => 'translate-article'], 'target_language', 'fr');

        $this->assertSame(['fr-FR'], $completion['values']);
    }

    public function testTopicIsFreeText(): void
    {
        $this->assertSame(
            ['values' => [], 'total' => 0, 'hasMore' => false],
            $this->complete(['type' => 'ref/prompt', 'name' => 'draft-article'], 'topic', 'gar')
        );
    }

    public function testArticleTemplateIdCompletes(): void
    {
        $completion = $this->complete(['type' => 'ref/resource', 'uri' => 'joomla://article/{id}'], 'id', '3');

        $this->assertSame(['30', '3'], $completion['values']);
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsAreInvalidParams(array $params, string $messageFragment): void
    {
        $response = $this->makeService()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'completion/complete', 'params' => $params]);

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertStringContainsString($messageFragment, $response['error']['message']);
    }

    public static function invalidRequests(): array
    {
        $argument = ['name' => 'article_id', 'value' => '1'];

        return [
            'unknown prompt names the prompts that exist' => [['ref' => ['type' => 'ref/prompt', 'name' => 'nope'], 'argument' => $argument], 'draft-article'],
            'unknown argument names the arguments that exist' => [['ref' => ['type' => 'ref/prompt', 'name' => 'draft-article'], 'argument' => ['name' => 'colour', 'value' => '']], 'topic, category'],
            'unknown template' => [['ref' => ['type' => 'ref/resource', 'uri' => 'joomla://menu/{id}'], 'argument' => ['name' => 'id', 'value' => '']], 'joomla://article/{id}'],
            'unknown ref type' => [['ref' => ['type' => 'ref/tool', 'name' => 'x'], 'argument' => $argument], 'ref/prompt or ref/resource'],
            'missing argument' => [['ref' => ['type' => 'ref/prompt', 'name' => 'draft-article']], 'argument'],
            'non-string value' => [['ref' => ['type' => 'ref/prompt', 'name' => 'draft-article'], 'argument' => ['name' => 'category', 'value' => 5]], 'argument'],
            'value over 200 characters' => [['ref' => ['type' => 'ref/prompt', 'name' => 'draft-article'], 'argument' => ['name' => 'category', 'value' => str_repeat('a', 201)]], '200'],
        ];
    }

    public function testPromptReferencesNeedPromptsEnabled(): void
    {
        $response = $this->makeService(promptsEnabled: false)->handle($this->request(['type' => 'ref/prompt', 'name' => 'draft-article'], 'category', ''));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertSame('Prompts are disabled by server policy', $response['error']['message']);
    }

    public function testNeitherFeatureEnabledIsMethodNotFound(): void
    {
        $service = $this->makeService(promptsEnabled: false, resourcesEnabled: false);

        $legacy = $service->handle($this->request(['type' => 'ref/prompt', 'name' => 'draft-article'], 'category', ''));
        $modernRequest = $this->request(['type' => 'ref/prompt', 'name' => 'draft-article'], 'category', '');
        $modernRequest['params']['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []];
        $modern = $service->handle($modernRequest);

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $legacy['error']['code']);
        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $modern['error']['code']);
        $this->assertSame(404, $service->getLastHttpStatus());
    }

    public function testCapabilityIsAdvertisedOnlyWhenSomethingIsCompletable(): void
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => []]];

        $this->assertStringContainsString('"completions":{}', (string) json_encode($this->makeService()->handle($initialize)));
        $this->assertStringNotContainsString('completions', (string) json_encode($this->makeService(promptsEnabled: false, resourcesEnabled: false)->handle($initialize)));
    }

    public function testModernCompletionResultsCarryResultType(): void
    {
        $request = $this->request(['type' => 'ref/prompt', 'name' => 'draft-article'], 'topic', '');
        $request['params']['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []];

        $result = $this->makeService()->handle($request)['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $result, 'completions are not cacheable');
    }

    public function testFetchFailureIsAnInternalErrorWithoutInternals(): void
    {
        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willThrowException(new ConnectException(
            'cURL error 7: Failed to connect to 10.0.0.5 for http://10.0.0.5/api/index.php/v1/content/categories',
            new Request('GET', 'http://10.0.0.5/api/index.php/v1/content/categories')
        ));

        $response = $this->makeService(rest: $rest)->handle($this->request(['type' => 'ref/prompt', 'name' => 'draft-article'], 'category', 'g'));

        $this->assertSame(JsonRpc::INTERNAL_ERROR, $response['error']['code']);
        $this->assertStringNotContainsString('10.0.0.5', $response['error']['message']);
    }

    public function testTitleSearchTreatsWildcardsAndOperatorsLiterally(): void
    {
        // Joomla builds LIKE '%term%' without escaping and parses id:/author:/content:
        // operators, so the typed text is never sent as-is: the longest literal
        // segment narrows the upstream search, and titles are then matched here.
        $searches = [];
        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willReturnCallback(static function (string $path, array $query = []) use (&$searches): array {
            $searches[] = $query['filter[search]'] ?? null;

            return ['data' => [
                ['type' => 'articles', 'id' => 7, 'attributes' => ['title' => '50% off']],
                ['type' => 'articles', 'id' => 9, 'attributes' => ['title' => '50 ways']],
                ['type' => 'articles', 'id' => 11, 'attributes' => ['title' => 'author:bob notes']],
            ]];
        });
        $service = $this->makeService(rest: $rest);
        $complete = fn (string $typed): array => $service->handle(
            $this->request(['type' => 'ref/prompt', 'name' => 'seo-audit-article'], 'article_id', $typed)
        )['result']['completion']['values'];

        $this->assertSame(['7'], $complete('50%'));
        $this->assertSame(['11'], $complete('author:bob'));
        $this->assertSame([], $complete('%_%'));
        $this->assertSame(['50', 'author'], $searches, 'no wildcard or operator reaches Joomla; a value with no literal text makes no call');
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function complete(array $ref, string $argument, string $value): array
    {
        $response = $this->makeService()->handle($this->request($ref, $argument, $value));
        $this->assertArrayNotHasKey('error', $response, json_encode($response['error'] ?? null));

        return $response['result']['completion'];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function request(array $ref, string $argument, string $value): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'completion/complete',
            'params' => ['ref' => $ref, 'argument' => ['name' => $argument, 'value' => $value]],
        ];
    }

    private function fakeRest(): RestClient
    {
        $rows = static fn (array $rows): array => ['data' => array_map(
            static fn (array $row): array => ['type' => 'x', 'id' => $row['id'], 'attributes' => $row],
            $rows
        )];

        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willReturnCallback(function (string $path, array $query = []) use ($rows): array {
            $this->calls[] = [$path, $query];
            if (($query['page[offset]'] ?? 0) > 0) {
                return ['data' => []];
            }

            return match (true) {
                str_ends_with($path, '/content/categories') => $rows([
                    ['id' => 1, 'title' => 'Kitchen Garden'], ['id' => 2, 'title' => 'Garden'],
                    ['id' => 3, 'title' => 'News'], ['id' => 4, 'title' => 'Gardening tips'],
                ]),
                str_ends_with($path, '/languages') => $rows([
                    ['id' => 1, 'lang_code' => 'fr-FR'], ['id' => 2, 'lang_code' => 'de-DE'], ['id' => 3, 'lang_code' => 'en-GB'],
                ]),
                isset($query['filter[search]']) => $rows([['id' => 7, 'title' => 'Garden A'], ['id' => 9, 'title' => 'Garden B']]),
                str_ends_with($path, '/content/articles') => $rows([['id' => 12], ['id' => 1200], ['id' => 3], ['id' => 120], ['id' => 30]]),
                default => ['data' => []],
            };
        });

        return $rest;
    }

    private function makeService(bool $promptsEnabled = true, bool $resourcesEnabled = true, ?RestClient $rest = null): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn(false);
        $policy->method('promptsEnabled')->willReturn($promptsEnabled);
        $policy->method('resourcesEnabled')->willReturn($resourcesEnabled);

        return new RpcService(
            $rest ?? $this->fakeRest(),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }
}
