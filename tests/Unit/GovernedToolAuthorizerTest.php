<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedToolAuthorizer;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolAccessPolicy;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GovernedToolAuthorizerTest extends TestCase
{
    public function testCatalogCoversEveryRegisteredTool(): void
    {
        $catalog = new ToolAccessPolicy();
        $registered = array_column((new ToolRegistry())->getAll(), 'name');

        $this->assertSame($registered, $catalog->toolNames());
        $this->assertCount(71, $catalog->toolNames());
    }

    public function testDeniedDirectCallDoesNotExecute(): void
    {
        $this->installCacheEnvironment(['page']);
        $service = $this->makeGovernedService($this->user(false));

        $response = $this->callTool($service, 'clear_cache', ['client' => 'site']);

        $this->assertTrue($service->wasLastCallBlocked());
        $this->assertTrue($response['result']['isError']);
        $this->assertSame([], Cache::$cleaned);
    }

    public function testAllowedMappedDirectCallExecutes(): void
    {
        $this->installCacheEnvironment(['page']);
        $service = $this->makeGovernedService($this->user(true, [['core.manage', 'com_cache']]));

        $response = $this->callTool($service, 'clear_cache', ['client' => 'site']);

        $this->assertFalse($service->wasLastCallBlocked());
        $this->assertFalse($response['result']['isError'] ?? false);
        $this->assertSame(['page'], Cache::$cleaned);
    }

    public function testApiOnlyToolBypassesLocalAclPreflight(): void
    {
        $rest = $this->createMock(RestClient::class);
        $rest->expects($this->once())->method('post')->willReturn(['data' => ['id' => 1]]);
        $service = $this->makeGovernedService($this->user(false), $rest);

        $response = $this->callTool($service, 'create_article', [
            'article' => ['title' => 'Title', 'catid' => 1, 'introtext' => 'Body'],
        ]);

        $this->assertFalse($service->wasLastCallBlocked());
        $this->assertArrayHasKey('result', $response);
    }

    public function testUnknownCatalogMappingIsDenied(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'future_direct_tool',
            'description' => 'A future direct tool',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false],
        ]);
        $registry->setExecutor('future_direct_tool', static fn (): array => ['executed' => true]);
        $service = $this->makeGovernedService($this->user(true), null, $registry);

        $response = $this->callTool($service, 'future_direct_tool', []);

        $this->assertTrue($service->wasLastCallBlocked());
        $this->assertTrue($response['result']['isError']);
    }

    public function testLegacyServiceDoesNotApplyGovernedAcl(): void
    {
        $this->installCacheEnvironment(['page']);
        $service = $this->makeLegacyService();

        $response = $this->callTool($service, 'clear_cache', ['client' => 'site']);

        $this->assertFalse($service->wasLastCallBlocked());
        $this->assertSame(['page'], Cache::$cleaned);
    }

    public function testMissingOrDisabledCurrentUserIsDenied(): void
    {
        $missing = $this->makeGovernedService(null);
        $disabled = $this->makeGovernedService($this->user(true, [], 1));

        $this->assertTrue($this->callTool($missing, 'clear_cache', ['client' => 'site'])['result']['isError']);
        $this->assertTrue($this->callTool($disabled, 'clear_cache', ['client' => 'site'])['result']['isError']);
    }

    public function testArticleDirectOperationUsesItemAssetAndEditOwnForTheOwner(): void
    {
        $user = $this->user(true, [['core.edit.own', 'com_content.article.19']]);
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            static fn (): object => $user,
            static fn (string $kind, int $id): ?array => $kind === 'article'
                ? ['id' => $id, 'created_by' => 7]
                : null
        );

        $this->assertTrue($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'get_article_by_id',
            ['id' => 19]
        ));
    }

    public function testAssociationWritesRequireEveryAssociatedArticleAsset(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_content.article.1']]),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id] : null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'set_article_associations',
            ['id' => 1, 'associated_ids' => [2]]
        ));
    }

    public function testAssociationReadsRequireThePrimaryItemAsset(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.manage', 'com_content']]),
            static fn (): ?array => null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'list_article_associations',
            ['id' => 1]
        ));
    }

    public function testMenuAssociationWritesFailClosedUntilItemAssetsAreResolved(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_menus.item.1']]),
            static fn (string $kind, int $id): ?array => $kind === 'menu_item' ? ['id' => $id] : null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'set_menu_item_associations',
            ['id' => 1, 'associated_ids' => [2]]
        ));
    }

    public function testExtensionStateRequiresAResolvedPlugin(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit.state', 'com_plugins']]),
            static fn (string $kind, int $id): ?array => ['id' => $id, 'type' => 'component']
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'set_extension_state',
            ['extension_id' => 1, 'enabled' => 1]
        ));
    }

    public function testExtensionStateExecutorRejectsNonPluginEvenAfterAclPreflight(): void
    {
        Factory::$dbo = new class {
            public function getQuery(bool $new = false): object { return new class {
                public function select(mixed $columns): self { return $this; }
                public function from(mixed $table): self { return $this; }
                public function where(mixed $condition): self { return $this; }
                public function __toString(): string { return ''; }
            }; }
            public function quoteName(mixed $name): mixed { return $name; }
            public function setQuery(mixed $query): self { return $this; }
            public function loadAssoc(): array { return ['extension_id' => 1, 'type' => 'component', 'protected' => 0, 'name' => 'Example']; }
            public function updateObject(string $table, object $object, string $key): bool { throw new \RuntimeException('Non-plugin state must not be written'); }
        };
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit.state', 'com_plugins']]),
            static fn (string $kind, int $id): ?array => ['id' => $id, 'type' => 'plugin']
        );
        $service = $this->makeService(
            null,
            null,
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            $authorizer
        );

        $response = $this->callTool($service, 'set_extension_state', ['extension_id' => 1, 'enabled' => 1]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Only plugin extensions', $response['result']['content'][0]['text']);
    }

    public function testModuleEditRequiresAResolvedModule(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_modules.module.1']]),
            static fn (): ?array => null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'update_module',
            ['id' => 1, 'title' => 'Changed']
        ));
    }

    public function testRenderedPageRequiresAnActivePrincipal(): void
    {
        $authorizer = new GovernedToolAuthorizer(new ToolAccessPolicy(), static fn (): ?object => null);

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'get_rendered_page',
            ['article_id' => 1]
        ));
    }

    public function testRenderedMenuPageRequiresResolvedMenuAcl(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.manage', 'com_menus']]),
            static fn (): ?array => null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'get_rendered_page',
            ['menu_item_id' => 1]
        ));
    }

    public function testRenderedArticlePageRequiresArticleEditAcl(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id, 'created_by' => 7] : null
        );

        $this->assertFalse($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'get_rendered_page',
            ['article_id' => 3]
        ));
    }

    public function testRenderedArticlePageAllowsArticleEditAcl(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_content.article.3']]),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id, 'created_by' => 9] : null
        );

        $this->assertTrue($authorizer->authorise(
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            'get_rendered_page',
            ['article_id' => 3]
        ));
    }

    public function testRenderedArticlePageAllowsEditOwnOnlyForOwner(): void
    {
        $owner = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit.own', 'com_content.article.3']]),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id, 'created_by' => 7] : null
        );
        $nonOwner = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit.own', 'com_content.article.3']]),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id, 'created_by' => 8] : null
        );
        $principal = new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token');

        $this->assertTrue($owner->authorise($principal, 'get_rendered_page', ['article_id' => 3]));
        $this->assertFalse($nonOwner->authorise($principal, 'get_rendered_page', ['article_id' => 3]));
    }

    public function testRenderedPageMalformedSelectorsAreDenied(): void
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_content.article.3']]),
            static fn (string $kind, int $id): ?array => ['id' => $id, 'created_by' => 7]
        );
        $principal = new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token');

        $this->assertFalse($authorizer->authorise($principal, 'get_rendered_page', []));
        $this->assertFalse($authorizer->authorise($principal, 'get_rendered_page', ['article_id' => 3, 'menu_item_id' => 4]));
    }

    public function testGovernedArticleReadDoesNotOverlayRawDatabaseContent(): void
    {
        $rest = $this->createMock(RestClient::class);
        $rest->expects($this->once())->method('get')->willReturn([
            'data' => ['id' => 1, 'attributes' => ['introtext' => 'API body']],
        ]);
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.edit', 'com_content.article.1']]),
            static fn (string $kind, int $id): ?array => $kind === 'article' ? ['id' => $id] : null
        );
        $service = $this->makeService(
            $rest,
            null,
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            $authorizer
        );

        $response = $this->callTool($service, 'get_article_by_id', ['id' => 1]);

        $this->assertFalse($response['result']['isError'] ?? false);
        $this->assertSame('API body', $response['result']['structuredContent']['data']['attributes']['introtext']);
    }

    public function testGovernedArticleSearchDoesNotOverlayRawDatabaseContent(): void
    {
        $rest = $this->createMock(RestClient::class);
        $rest->expects($this->once())->method('get')->willReturn([
            'data' => [['id' => 1, 'attributes' => ['introtext' => 'API body']]],
        ]);
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            fn (): object => $this->user(true, [['core.manage', 'com_content']])
        );
        $service = $this->makeService(
            $rest,
            null,
            new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'),
            $authorizer
        );

        $response = $this->callTool($service, 'search_articles', []);

        $this->assertFalse($response['result']['isError'] ?? false);
        $this->assertSame('API body', $response['result']['structuredContent']['data'][0]['attributes']['introtext']);
    }

    protected function tearDown(): void
    {
        Factory::reset();
        Cache::reset();
    }

    private function makeGovernedService(?object $user, ?RestClient $rest = null, ?ToolRegistry $registry = null): RpcService
    {
        $authorizer = new GovernedToolAuthorizer(
            new ToolAccessPolicy(),
            static fn (int $id): ?object => $user
        );

        return $this->makeService($rest, $registry, new AuthenticatedPrincipal(1, 'selector', 7, 'Client', 'token'), $authorizer);
    }

    private function makeLegacyService(): RpcService
    {
        return $this->makeService();
    }

    private function makeService(
        ?RestClient $rest = null,
        ?ToolRegistry $registry = null,
        ?AuthenticatedPrincipal $principal = null,
        ?GovernedToolAuthorizer $authorizer = null,
        ?PolicyService $policy = null
    ): RpcService {
        if ($policy === null) {
            $policy = $this->createMock(PolicyService::class);
            $policy->method('isToolAllowed')->willReturn(true);
            $policy->method('isReadOnly')->willReturn(false);
            $policy->method('resourcesEnabled')->willReturn(false);
            $policy->method('promptsEnabled')->willReturn(false);
        }

        return new RpcService(
            $rest ?? $this->createMock(RestClient::class),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            $registry ?? new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry(),
            'joomla-mcp-server',
            100,
            $principal,
            $authorizer
        );
    }

    private function user(bool $allowed, array $permissions = [], int $block = 0): object
    {
        return new class ($allowed, $permissions, $block) {
            public int $id = 7;

            public function __construct(private readonly bool $allowed, private readonly array $permissions, public int $block)
            {
            }

            public function authorise(string $action, ?string $asset = null): bool
            {
                return $this->allowed && in_array([$action, $asset], $this->permissions, true);
            }
        };
    }

    private function callTool(RpcService $service, string $name, array $arguments): array
    {
        return $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    private function installCacheEnvironment(array $groups): void
    {
        Cache::reset();
        Factory::reset();
        Cache::$availableGroups = $groups;
        Factory::$application = new class {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function getDispatcher(): object { return new class { public function dispatch(string $name, object $event): object { return $event; } }; }
        };
    }
}
