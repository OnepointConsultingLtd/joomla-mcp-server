<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
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

class RpcServiceDiagnosticsTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::reset();
    }

    public function testGetRenderedPageBuildsArticlePathFromCatid(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn([
            // No top-level data.id so injectRawArticleContent skips Factory::getDbo().
            'data' => [
                'attributes' => [
                    'catid' => 12,
                    'title' => 'Hello',
                ],
            ],
        ]);
        $rest->expects($this->once())->method('fetchRenderedPage')
            ->with('index.php?option=com_content&view=article&id=7&catid=12')
            ->willReturn([
                'status' => 200,
                'final_url' => 'https://example.com/cat/hello',
                'body' => '<html><body><p>Hello</p></body></html>',
                'truncated' => false,
            ]);

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'get_rendered_page', [
            'article_id' => 7,
        ]));

        $this->assertSame('index.php?option=com_content&view=article&id=7&catid=12', $result['data']['requested_path']);
        $this->assertSame('https://example.com/cat/hello', $result['data']['final_url']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('html', $result['data']['format']);
        $this->assertSame('<html><body><p>Hello</p></body></html>', $result['data']['content']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertSame(strlen($result['data']['content']), $result['data']['content_bytes']);
    }

    public function testGetRenderedPageBuildsMenuPath(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('get');
        $rest->expects($this->once())->method('fetchRenderedPage')
            ->with('index.php?Itemid=42')
            ->willReturn([
                'status' => 200,
                'final_url' => 'https://example.com/about',
                'body' => '<p>About</p>',
                'truncated' => true,
            ]);

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'get_rendered_page', [
            'menu_item_id' => 42,
        ]));

        $this->assertSame('index.php?Itemid=42', $result['data']['requested_path']);
        $this->assertSame('https://example.com/about', $result['data']['final_url']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertSame('<p>About</p>', $result['data']['content']);
    }

    public function testGetRenderedPageRejectsBothIdentifiers(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('fetchRenderedPage');

        $response = $this->callTool($this->makeService($rest), 'get_rendered_page', [
            'article_id' => 1,
            'menu_item_id' => 2,
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame(
            'Provide exactly one of article_id or menu_item_id',
            $response['result']['content'][0]['text']
        );
    }

    public function testGetRenderedPageRejectsNeitherIdentifier(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->never())->method('fetchRenderedPage');

        $response = $this->callTool($this->makeService($rest), 'get_rendered_page', []);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame(
            'Provide exactly one of article_id or menu_item_id',
            $response['result']['content'][0]['text']
        );
    }

    public function testGetRenderedPageTextFormatStripsMarkup(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->once())->method('fetchRenderedPage')
            ->with('index.php?Itemid=3')
            ->willReturn([
                'status' => 200,
                'final_url' => 'https://example.com/',
                'body' => '<html><body><script>secret()</script><p>Hello</p><style>.x{color:red}</style><div>World</div></body></html>',
                'truncated' => false,
            ]);

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'get_rendered_page', [
            'menu_item_id' => 3,
            'format' => 'text',
        ]));

        $this->assertSame('text', $result['data']['format']);
        $this->assertSame('Hello' . "\n\n" . 'World', $result['data']['content']);
        $this->assertStringNotContainsString('secret', $result['data']['content']);
        $this->assertStringNotContainsString('color:red', $result['data']['content']);
    }

    public function testGetRenderedPagePassesThroughStatusFinalUrlAndTruncated(): void
    {
        $rest = $this->createRestMock();
        $rest->expects($this->once())->method('fetchRenderedPage')->willReturn([
            'status' => 404,
            'final_url' => 'https://example.com/missing',
            'body' => '<p>Not found</p>',
            'truncated' => true,
        ]);

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'get_rendered_page', [
            'menu_item_id' => 99,
        ]));

        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('https://example.com/missing', $result['data']['final_url']);
        $this->assertTrue($result['data']['truncated']);
    }

    public function testSeoAuditArticlesReportsEveryIssueCodeAndIgnoresCrossCategoryAliasAndMetakey(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(static function (string $path, array $query = []): array {
            if (!str_contains($path, 'content/articles')) {
                return ['data' => []];
            }

            return [
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => [
                            'title' => '',
                            'alias' => 'shared',
                            'catid' => 2,
                            'metadesc' => '',
                            'metakey' => '',
                        ],
                    ],
                    [
                        'id' => '2',
                        'attributes' => [
                            'title' => 'Short desc',
                            'alias' => 'short-desc',
                            'catid' => 2,
                            'metadesc' => 'Too short',
                            'metakey' => '',
                        ],
                    ],
                    [
                        'id' => '3',
                        'attributes' => [
                            'title' => 'Long desc',
                            'alias' => 'long-desc',
                            'catid' => 2,
                            'metadesc' => str_repeat('a', 161),
                            'metakey' => 'ignored,keywords',
                        ],
                    ],
                    [
                        'id' => '4',
                        'attributes' => [
                            'title' => 'Duplicate A',
                            'alias' => 'twins',
                            'catid' => 2,
                            'metadesc' => str_repeat('b', 80),
                        ],
                    ],
                    [
                        'id' => '5',
                        'attributes' => [
                            'title' => 'Duplicate B',
                            'alias' => 'twins',
                            'catid' => 2,
                            'metadesc' => str_repeat('c', 80),
                        ],
                    ],
                    [
                        'id' => '6',
                        'attributes' => [
                            'title' => 'Other category',
                            'alias' => 'twins',
                            'catid' => 9,
                            'metadesc' => str_repeat('d', 80),
                        ],
                    ],
                    [
                        'id' => '7',
                        'attributes' => [
                            'title' => 'Healthy',
                            'alias' => 'healthy',
                            'catid' => 2,
                            'metadesc' => str_repeat('e', 80),
                            'metakey' => '',
                        ],
                    ],
                ],
            ];
        });

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'seo_audit_articles', []));

        $byId = [];
        foreach ($result['data'] as $row) {
            $byId[$row['id']] = $row;
        }

        $this->assertArrayNotHasKey(6, $byId);
        $this->assertArrayNotHasKey(7, $byId);
        $this->assertSame(['missing_title', 'missing_metadesc'], array_column($byId[1]['issues'], 'code'));
        $this->assertSame(['short_metadesc'], array_column($byId[2]['issues'], 'code'));
        $this->assertSame(['long_metadesc'], array_column($byId[3]['issues'], 'code'));
        $this->assertSame(161, $byId[3]['issues'][0]['metadesc_length']);
        $this->assertSame(['duplicate_alias'], array_column($byId[4]['issues'], 'code'));
        $this->assertSame([5], $byId[4]['issues'][0]['sibling_ids']);
        $this->assertSame([4], $byId[5]['issues'][0]['sibling_ids']);

        foreach ($result['data'] as $row) {
            foreach ($row['issues'] as $issue) {
                $this->assertNotSame('missing_metakey', $issue['code']);
            }
        }

        $this->assertSame(7, $result['summary']['articles_checked']);
        $this->assertSame(5, $result['summary']['articles_with_issues']);
        $this->assertSame(1, $result['summary']['issue_counts']['missing_title']);
        $this->assertSame(1, $result['summary']['issue_counts']['missing_metadesc']);
        $this->assertSame(1, $result['summary']['issue_counts']['short_metadesc']);
        $this->assertSame(1, $result['summary']['issue_counts']['long_metadesc']);
        $this->assertSame(2, $result['summary']['issue_counts']['duplicate_alias']);
        $this->assertArrayNotHasKey('articles_limit_reached', $result['summary']);
    }

    public function testSeoAuditArticlesPaginatesIssueRowsLocally(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn([
            'data' => [
                [
                    'id' => '1',
                    'attributes' => ['title' => '', 'alias' => 'a', 'catid' => 1, 'metadesc' => str_repeat('x', 80)],
                ],
                [
                    'id' => '2',
                    'attributes' => ['title' => '', 'alias' => 'b', 'catid' => 1, 'metadesc' => str_repeat('y', 80)],
                ],
                [
                    'id' => '3',
                    'attributes' => ['title' => '', 'alias' => 'c', 'catid' => 1, 'metadesc' => str_repeat('z', 80)],
                ],
            ],
        ]);

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'seo_audit_articles', [
            'limit' => 1,
            'offset' => 1,
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertSame(2, $result['data'][0]['id']);
        $this->assertSame(3, $result['pagination']['total_count']);
        $this->assertSame(1, $result['pagination']['count']);
        $this->assertSame(1, $result['pagination']['offset']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertSame(2, $result['pagination']['next_offset']);
        $this->assertSame(3, $result['summary']['articles_with_issues']);
    }

    public function testCheckInternalLinksStatusMatrixAndDoesNotFetchExternalUrls(): void
    {
        $rest = $this->createRestMock();
        $rest->method('getBaseUrl')->willReturn('https://example.com');
        $rest->expects($this->never())->method('fetchRenderedPage');
        $rest->expects($this->never())->method('fetchUrlContent');
        $rest->method('get')->willReturnCallback(static function (string $path, array $query = []): array {
            if (str_contains($path, 'menus/site/items')) {
                return [
                    'data' => [
                        [
                            'id' => '10',
                            'attributes' => ['path' => 'about/team', 'alias' => 'team'],
                        ],
                    ],
                ];
            }
            if (str_contains($path, 'content/articles') && ($query['filter[state]'] ?? null) === 0) {
                return [
                    'data' => [
                        [
                            'id' => '8',
                            'attributes' => [
                                'title' => 'Draft',
                                'alias' => 'draft-post',
                                'state' => 0,
                                'introtext' => '',
                                'fulltext' => '',
                            ],
                        ],
                    ],
                ];
            }

            return [
                'data' => [
                    [
                        'id' => '5',
                        'attributes' => [
                            'title' => 'Source',
                            'alias' => 'source',
                            'state' => 1,
                            'introtext' => '<a href="index.php?option=com_content&amp;view=article&amp;id=5">self</a>'
                                . '<a href="index.php?option=com_content&view=article&id=99">missing</a>'
                                . '<a href="index.php?option=com_content&view=article&id=8">draft</a>',
                            'fulltext' => '<a href="/mystery/path">unknown</a>'
                                . '<a href="https://other.com/x">external</a>'
                                . '<a href="mailto:a@b.com">skip</a>',
                        ],
                    ],
                ],
            ];
        });

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'check_internal_links', []));

        $this->assertCount(1, $result['data']);
        $links = [];
        foreach ($result['data'][0]['links'] as $link) {
            $links[$link['url']] = $link;
        }

        $this->assertSame('ok', $links['index.php?option=com_content&view=article&id=5']['status']);
        $this->assertSame(5, $links['index.php?option=com_content&view=article&id=5']['target_article_id']);
        $this->assertSame('missing', $links['index.php?option=com_content&view=article&id=99']['status']);
        $this->assertSame('unpublished', $links['index.php?option=com_content&view=article&id=8']['status']);
        $this->assertSame('unknown', $links['/mystery/path']['status']);
        $this->assertSame('not_checked', $links['https://other.com/x']['status']);
        $this->assertArrayNotHasKey('mailto:a@b.com', $links);

        $this->assertSame(1, $result['summary']['articles_checked']);
        $this->assertSame(6, $result['summary']['links_total']);
        $this->assertSame(4, $result['summary']['internal']);
        $this->assertSame(1, $result['summary']['external']);
        $this->assertSame(1, $result['summary']['ok']);
        $this->assertSame(1, $result['summary']['missing']);
        $this->assertSame(1, $result['summary']['unpublished']);
        $this->assertSame(1, $result['summary']['unknown']);
    }

    public function testCheckInternalLinksSingleArticleMode(): void
    {
        $rest = $this->createRestMock();
        $rest->method('getBaseUrl')->willReturn('https://example.com');
        $rest->method('get')->willReturnCallback(static function (string $path, array $query = []): array {
            if (preg_match('#content/articles/7$#', $path)) {
                return [
                    'data' => [
                        'attributes' => [
                            'title' => 'One',
                            'introtext' => '<a href="https://other.com/x">ext</a>',
                            'fulltext' => '',
                        ],
                    ],
                ];
            }
            if (str_contains($path, 'menus/site/items')) {
                return ['data' => []];
            }
            if (($query['filter[state]'] ?? null) === 0) {
                return ['data' => []];
            }

            return [
                'data' => [
                    [
                        'id' => '5',
                        'attributes' => [
                            'title' => 'Other published',
                            'alias' => 'other',
                            'introtext' => '<a href="/should-not-be-scanned">x</a>',
                            'fulltext' => '',
                        ],
                    ],
                ],
            ];
        });

        $result = $this->toolResult($this->callTool($this->makeService($rest), 'check_internal_links', [
            'article_id' => 7,
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertSame(7, $result['data'][0]['id']);
        $this->assertSame('One', $result['data'][0]['title']);
        $this->assertSame('not_checked', $result['data'][0]['links'][0]['status']);
        $this->assertSame(1, $result['summary']['articles_checked']);
        $this->assertSame(1, $result['summary']['external']);
    }

    public function testGetSystemInformationReturnsInfoPhpSettingsAndDirectoryWritability(): void
    {
        $this->installSysinfoModel($this->sysinfoModel());

        $result = $this->toolResult($this->callTool($this->makeService(), 'get_system_information', []));

        $this->assertSame(['info', 'php_settings', 'directories'], array_keys($result['data']));
        $this->assertSame('8.0.36', $result['data']['info']['dbversion']);
        $this->assertSame('8.2.18', $result['data']['info']['phpversion']);
        $this->assertSame('256M', $result['data']['php_settings']['memory_limit']);
        $this->assertSame('xxxxxx', $result['data']['php_settings']['open_basedir']);
        $this->assertSame([
            ['name' => 'administrator/components', 'writable' => true],
            ['name' => 'images', 'writable' => false],
            ['name' => 'log', 'writable' => true],
            ['name' => 'tmp', 'writable' => true],
            ['name' => 'administrator/cache', 'writable' => false],
        ], $result['data']['directories']);
    }

    public function testGetSystemInformationRequestsOnlyTheEssentialSectionsWithPublicDirectoryNames(): void
    {
        // Config, phpinfo and extensions were scoped out deliberately, and the
        // non-public directory listing keys log/tmp by absolute server path.
        $model = $this->sysinfoModel();
        $this->installSysinfoModel($model);

        $this->toolResult($this->callTool($this->makeService(), 'get_system_information', []));

        $this->assertSame([
            ['info', true],
            ['phpSettings', true],
            ['directory', true],
        ], $model->requested);
    }

    public function testGetSystemInformationOmitsTheCallersUserAgent(): void
    {
        // Joomla reports the requesting browser's user agent. Through MCP that is
        // the client's own, which says nothing about the site.
        $this->installSysinfoModel($this->sysinfoModel());

        $result = $this->toolResult($this->callTool($this->makeService(), 'get_system_information', []));

        $this->assertArrayNotHasKey('useragent', $result['data']['info']);
    }

    public function testGetSystemInformationNeverReturnsAnAbsoluteDirectoryPath(): void
    {
        // Even in its public listing, Joomla keys a custom cache_path by the raw path.
        $this->installSysinfoModel($this->sysinfoModel([
            'images' => ['writable' => true, 'message' => ''],
            '/var/www/private/cache' => ['writable' => false, 'message' => 'COM_ADMIN_CACHE_DIRECTORY'],
            'D:\\sites\\extra' => ['writable' => true, 'message' => ''],
        ]));

        $result = $this->toolResult($this->callTool($this->makeService(), 'get_system_information', []));

        $this->assertSame([
            ['name' => 'images', 'writable' => true],
            ['name' => 'cache', 'writable' => false],
        ], $result['data']['directories']);
    }

    public function testGetSystemInformationSchemaSerialisesItsEmptyPropertiesAsAnObject(): void
    {
        // JSON Schema requires properties to be an object; an empty PHP array
        // would go out as [], which strict MCP clients reject.
        $tools = array_column((new ToolRegistry())->getAll(), null, 'name');

        $this->assertSame('{}', json_encode($tools['get_system_information']['inputSchema']['properties']));
    }

    public function testGetSystemInformationRunsInReadOnlyMode(): void
    {
        $this->installSysinfoModel($this->sysinfoModel());
        $service = $this->makeService(null, true);

        $response = $this->callTool($service, 'get_system_information', []);

        $this->assertFalse($service->wasLastCallBlocked());
        $this->toolResult($response);
    }

    public function testGetSystemInformationExplainsWhenTheSysinfoModelCannotLoad(): void
    {
        $this->installSysinfoModel(null);
        $service = $this->makeService();

        $response = $this->callTool($service, 'get_system_information', []);

        $this->assertTrue($response['result']['isError']);
        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('system information', $response['result']['content'][0]['text']);
    }

    public function testGetSystemInformationDoesNotRelayJoomlaExceptionMessages(): void
    {
        // Both extend RuntimeException, whose messages executors normally pass
        // through: DirectoryIterator names the absolute path it could not open,
        // and Joomla's database exceptions carry the failing SQL.
        $model = $this->sysinfoModel(null, new \UnexpectedValueException(
            'DirectoryIterator::__construct(/var/www/private/administrator/manifests): Failed to open directory'
        ));
        $this->installSysinfoModel($model);
        $service = $this->makeService();

        $response = $this->callTool($service, 'get_system_information', []);

        $this->assertTrue($response['result']['isError']);
        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringNotContainsString('/var/www', $response['result']['content'][0]['text']);
    }

    /**
     * Stands in for com_admin's SysinfoModel after getSafeData() has applied
     * Joomla's privacy filter, so the values are what core would hand back.
     *
     * @param  array<string, array{writable:bool, message:string}>|null  $directories
     */
    private function sysinfoModel(?array $directories = null, ?\Throwable $failure = null): object
    {
        return new class ($directories, $failure) {
            /** @var list<array{0:string, 1:bool}> */
            public array $requested = [];

            public function __construct(private readonly ?array $directories, private readonly ?\Throwable $failure)
            {
            }

            public function getSafeData(string $dataType, bool $public = true): array
            {
                $this->requested[] = [$dataType, $public];
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return match ($dataType) {
                    'info' => [
                        'php' => 'Linux web01 6.1.0 x86_64',
                        'dbserver' => 'mysql',
                        'dbversion' => '8.0.36',
                        'phpversion' => '8.2.18',
                        'server' => 'Apache',
                        'sapi_name' => 'fpm-fcgi',
                        'version' => 'Joomla! 5.2.0 Stable',
                        'useragent' => 'claude-desktop/1.0',
                    ],
                    'phpSettings' => [
                        'memory_limit' => '256M',
                        'upload_max_filesize' => '32M',
                        'open_basedir' => 'xxxxxx',
                        'gd' => true,
                    ],
                    'directory' => $this->directories ?? [
                        'administrator/components' => ['writable' => true, 'message' => ''],
                        'images' => ['writable' => false, 'message' => ''],
                        'log' => ['writable' => true, 'message' => 'COM_ADMIN_LOG_DIRECTORY'],
                        'tmp' => ['writable' => true, 'message' => 'COM_ADMIN_TEMP_DIRECTORY'],
                        'administrator/cache' => ['writable' => false, 'message' => 'COM_ADMIN_CACHE_DIRECTORY'],
                    ],
                    default => ['unexpected' => $dataType],
                };
            }
        };
    }

    private function installSysinfoModel(?object $model): void
    {
        $factory = new class ($model) {
            public function __construct(private readonly ?object $model)
            {
            }

            // Like Joomla's MVCFactory, answers null when there is no such model.
            public function createModel(string $name, string $prefix = '', array $config = []): ?object
            {
                return $name === 'Sysinfo' && $prefix === 'Administrator' ? $this->model : null;
            }
        };

        Factory::$application = new class ($factory) {
            public function __construct(private readonly object $factory)
            {
            }

            public function bootComponent(string $component): object
            {
                if ($component !== 'com_admin') {
                    throw new \RuntimeException('Unexpected component ' . $component);
                }

                return new class ($this->factory) {
                    public function __construct(private readonly object $factory)
                    {
                    }

                    public function getMVCFactory(): object
                    {
                        return $this->factory;
                    }
                };
            }
        };
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
    }

    private function makeService(?RestClient $rest = null, bool $readOnly = false): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn($readOnly);
        $policy->method('resourcesEnabled')->willReturn(false);
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
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function toolResult(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('isError', $response['result']);
        $this->assertArrayHasKey('structuredContent', $response['result']);

        return $response['result']['structuredContent'];
    }
}
