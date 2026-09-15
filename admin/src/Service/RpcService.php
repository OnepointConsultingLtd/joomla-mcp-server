<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Event\Cache\AfterPurgeEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Version as JoomlaVersion;
use Joomla\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;

class RpcService
{
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const DEFAULT_TOOLS_LIST_PAGE_SIZE = 100;

    private const RESOURCES_ARTICLE_LIMIT = 50;

    private const ARTICLE_RESOURCE_URI_PREFIX = 'joomla://article/';

    /**
     * Cache groups clear_cache must never wipe: their loss would disrupt the
     * very request doing the clearing — the SSE relay carries in-flight
     * responses for stdio-bridge sessions and the rate limiter holds active
     * counters. The component's read cache (com_mcpserver) IS cleared on
     * purpose — it may hold the same stale content the caller is flushing.
     */
    private const PROTECTED_CACHE_GROUPS = ['mcp_sse', 'com_mcpserver_ratelimit'];

    private const SEO_METADESC_MIN = 50;

    private const SEO_METADESC_MAX = 160;

    private const FETCH_ALL_PAGES_CAP = 5000;

    private static ?string $cachedVersion = null;

    private RestClient $rest;
    private CacheService $cache;
    private PolicyService $policy;
    private LoggerInterface $logger;
    private ToolRegistry $toolRegistry;
    private SchemaValidator $validator;
    private PromptRegistry $promptRegistry;
    private string $serverName;
    private int $toolsListPageSize;
    private ?AuthenticatedPrincipal $principal;
    private ?GovernedToolAuthorizer $authorizer;
    private bool $allowRawArticleContent;

    /**
     * Whether the last handled tools/call was denied by policy (disabled tool
     * or read-only mode). Policy denials are returned to the client as MCP
     * tool results with isError=true — not JSON-RPC error responses — so the
     * HTTP layer cannot see them in the response envelope. It reads this flag
     * instead to log them as 'blocked' rather than 'ok'.
     */
    private bool $lastCallBlocked = false;

    private bool $lastCallFailed = false;

    public function __construct(
        RestClient $rest,
        CacheService $cache,
        PolicyService $policy,
        LoggerInterface $logger,
        ToolRegistry $toolRegistry,
        SchemaValidator $validator,
        PromptRegistry $promptRegistry,
        string $serverName = 'joomla-mcp-server',
        int $toolsListPageSize = self::DEFAULT_TOOLS_LIST_PAGE_SIZE,
        ?AuthenticatedPrincipal $principal = null,
        ?GovernedToolAuthorizer $authorizer = null
    ) {
        $this->rest = $rest;
        $this->cache = $cache;
        $this->policy = $policy;
        $this->logger = $logger;
        $this->toolRegistry = $toolRegistry;
        $this->validator = $validator;
        $this->promptRegistry = $promptRegistry;
        $this->serverName = $serverName;
        $this->toolsListPageSize = max(1, $toolsListPageSize);
        // A governed principal without an authorizer would run every
        // direct/mixed executor — install_extension, update_template_file and
        // the rest of the core.admin set — with no local ACL check at all.
        // Reject the pairing here rather than relying on every construction
        // site to remember it.
        if ($principal !== null && $authorizer === null) {
            throw new \LogicException('A governed principal requires a GovernedToolAuthorizer');
        }

        $this->principal = $principal;
        $this->authorizer = $authorizer;
        $this->allowRawArticleContent = $principal === null;

        $this->registerToolExecutors();
        $this->registerPromptBuilders();
    }

    private function registerToolExecutors(): void
    {
        $executors = [
            'get_article_by_id'       => fn(array $p) => $this->getArticleById($p),
            'search_articles'         => fn(array $p) => $this->searchArticles($p),
            'create_article'          => fn(array $p) => $this->createArticle($p),
            'update_article'          => fn(array $p) => $this->updateArticle($p),
            'delete_article'          => fn(array $p) => $this->deleteArticle($p),
            'list_article_versions'   => fn(array $p) => $this->listArticleVersions($p),
            'get_article_version'     => fn(array $p) => $this->getArticleVersion($p),
            'diff_article_versions'   => fn(array $p) => $this->diffArticleVersions($p),
            'keep_article_version'    => fn(array $p) => $this->keepArticleVersion($p),
            'delete_article_version'  => fn(array $p) => $this->deleteArticleVersion($p),
            'restore_article_version' => fn(array $p) => $this->restoreArticleVersion($p),
            'create_custom_module'    => fn(array $p) => $this->createCustomModule($p),
            'list_custom_modules'     => fn(array $p) => $this->listCustomModules($p),
            'get_custom_module_by_id' => fn(array $p) => $this->getCustomModuleById($p),
            'update_custom_module'    => fn(array $p) => $this->updateCustomModule($p),
            'list_modules'            => fn(array $p) => $this->listModules($p),
            'get_module_by_id'        => fn(array $p) => $this->getModuleById($p),
            'update_module'           => fn(array $p) => $this->updateModule($p),
            'list_menus'              => fn(array $p) => $this->listMenus($p),
            'list_menu_items'         => fn(array $p) => $this->listMenuItems($p),
            'get_menu_item'           => fn(array $p) => $this->getMenuItem($p),
            'create_menu_item'        => fn(array $p) => $this->createMenuItem($p),
            'update_menu_item'        => fn(array $p) => $this->updateMenuItem($p),
            'list_media'              => fn(array $p) => $this->listMedia($p),
            'get_media'               => fn(array $p) => $this->getMedia($p),
            'upload_media'            => fn(array $p) => $this->uploadMedia($p),
            'create_media_folder'     => fn(array $p) => $this->createMediaFolder($p),
            'update_media'            => fn(array $p) => $this->updateMedia($p),
            'delete_media'            => fn(array $p) => $this->deleteMedia($p),
            'list_content_languages'        => fn(array $p) => $this->listContentLanguages($p),
            'get_content_language'          => fn(array $p) => $this->getContentLanguage($p),
            'create_content_language'       => fn(array $p) => $this->createContentLanguage($p),
            'update_content_language'       => fn(array $p) => $this->updateContentLanguage($p),
            'delete_content_language'       => fn(array $p) => $this->deleteContentLanguage($p),
            'list_installed_languages'      => fn(array $p) => $this->listInstalledLanguages($p),
            'list_template_styles'          => fn(array $p) => $this->listTemplateStyles($p),
            'get_template_style'            => fn(array $p) => $this->getTemplateStyle($p),
            'create_template_style'         => fn(array $p) => $this->createTemplateStyle($p),
            'update_template_style'         => fn(array $p) => $this->updateTemplateStyle($p),
            'delete_template_style'         => fn(array $p) => $this->deleteTemplateStyle($p),
            'list_installed_templates'      => fn(array $p) => $this->listInstalledTemplates($p),
            'install_extension'             => fn(array $p) => $this->installExtension($p),
            'list_template_files'           => fn(array $p) => $this->listTemplateFiles($p),
            'get_template_file'             => fn(array $p) => $this->getTemplateFile($p),
            'update_template_file'          => fn(array $p) => $this->updateTemplateFile($p),
            'create_template_override'      => fn(array $p) => $this->createTemplateOverride($p),
            'list_article_associations'     => fn(array $p) => $this->listArticleAssociations($p),
            'set_article_associations'      => fn(array $p) => $this->setArticleAssociations($p),
            'list_menu_item_associations'   => fn(array $p) => $this->listMenuItemAssociations($p),
            'set_menu_item_associations'    => fn(array $p) => $this->setMenuItemAssociations($p),
            'list_categories'               => fn(array $p) => $this->listCategories($p),
            'get_category'                  => fn(array $p) => $this->getCategory($p),
            'create_category'               => fn(array $p) => $this->createCategory($p),
            'update_category'               => fn(array $p) => $this->updateCategory($p),
            'delete_category'               => fn(array $p) => $this->deleteCategory($p),
            'list_tags'                     => fn(array $p) => $this->listTags($p),
            'get_tag'                       => fn(array $p) => $this->getTag($p),
            'create_tag'                    => fn(array $p) => $this->createTag($p),
            'update_tag'                    => fn(array $p) => $this->updateTag($p),
            'delete_tag'                    => fn(array $p) => $this->deleteTag($p),
            'list_extensions'               => fn(array $p) => $this->listExtensions($p),
            'set_extension_state'           => fn(array $p) => $this->setExtensionState($p),
            'uninstall_extension'           => fn(array $p) => $this->uninstallExtension($p),
            'create_menu'                   => fn(array $p) => $this->createMenu($p),
            'delete_menu_item'              => fn(array $p) => $this->deleteMenuItem($p),
            'create_module'                 => fn(array $p) => $this->createModule($p),
            'delete_module'                 => fn(array $p) => $this->deleteModule($p),
            'clear_cache'                   => fn(array $p) => $this->clearJoomlaCache($p),
            'get_rendered_page'             => fn(array $p) => $this->getRenderedPage($p),
            'seo_audit_articles'            => fn(array $p) => $this->seoAuditArticles($p),
            'check_internal_links'          => fn(array $p) => $this->checkInternalLinks($p),
            'list_field_groups'             => fn(array $p) => $this->listFieldGroups($p),
            'get_field_group'               => fn(array $p) => $this->getFieldGroup($p),
            'create_field_group'            => fn(array $p) => $this->createFieldGroup($p),
            'update_field_group'            => fn(array $p) => $this->updateFieldGroup($p),
            'delete_field_group'            => fn(array $p) => $this->deleteFieldGroup($p),
            'reorder_field_groups'          => fn(array $p) => $this->reorderFieldGroups($p),
            'list_fields'                   => fn(array $p) => $this->listFields($p),
            'get_field'                     => fn(array $p) => $this->getField($p),
            'find_field_by_name'            => fn(array $p) => $this->findFieldByName($p),
            'create_field'                  => fn(array $p) => $this->createField($p),
            'update_field'                  => fn(array $p) => $this->updateField($p),
            'delete_field'                  => fn(array $p) => $this->deleteField($p),
            'reorder_fields'                => fn(array $p) => $this->reorderFields($p),
            'get_item_field_values'         => fn(array $p) => $this->getItemFieldValues($p),
            'set_item_field_values'         => fn(array $p) => $this->setItemFieldValues($p),
        ];

        foreach ($executors as $name => $executor) {
            $this->toolRegistry->setExecutor($name, $executor);
        }

        foreach ($this->toolRegistry->getAll() as $tool) {
            if (!$this->toolRegistry->hasExecutor($tool['name'])) {
                throw new \LogicException("Tool '{$tool['name']}' has a schema but no executor");
            }
        }
    }

    private function registerPromptBuilders(): void
    {
        $this->promptRegistry->setBuilder('draft-article', fn (array $p) => $this->buildDraftArticlePrompt($p));
        $this->promptRegistry->setBuilder('seo-audit-article', fn (array $p) => $this->buildSeoAuditPrompt($p));
        $this->promptRegistry->setBuilder('translate-article', fn (array $p) => $this->buildTranslateArticlePrompt($p));

        foreach ($this->promptRegistry->getAll() as $prompt) {
            if (!$this->promptRegistry->hasBuilder($prompt['name'])) {
                throw new \LogicException("Prompt '{$prompt['name']}' has a definition but no builder");
            }
        }
    }

    public function wasLastCallBlocked(): bool
    {
        return $this->lastCallBlocked;
    }

    /**
     * Whether the last tools/call threw and was converted into an MCP tool
     * error result.
     *
     * Such a failure is returned as a JSON-RPC *success* envelope carrying
     * isError=true (per the MCP spec), so it is invisible to a caller
     * inspecting $response['error']. Without this the audit trail and the
     * Joomla Action Log would record a failed mutation as 'ok'.
     */
    public function wasLastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    public function handle(array $request): ?array
    {
        $this->lastCallBlocked = false;
        $this->lastCallFailed = false;

        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request);
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        $this->logger->info('Handling RPC request', [
            'method' => $method,
            'has_id' => !$isNotification,
            'server' => $this->serverName
        ]);

        if ($method === 'notifications/initialized'
            || $method === 'notifications/cancelled'
            || $method === 'notifications/progress'
            || $method === 'notifications/roots/list_changed'
        ) {
            return $isNotification ? null : JsonRpc::successResponse($id, null);
        }

        if ($method === 'initialize' || $method === 'capabilities') {
            $response = $this->handleCapabilities($id, $params);
            return $isNotification ? null : $response;
        }

        if ($method === 'ping') {
            return $isNotification ? null : JsonRpc::successResponse($id, new \stdClass());
        }

        if ($method === 'tools/list') {
            $response = $this->handleListTools($id, $params);
            return $isNotification ? null : $response;
        }

        if ($method === 'tools/call') {
            $response = $this->handleCallTool($id, $params);
            return $isNotification ? null : $response;
        }

        if ($method === 'resources/list') {
            $response = $this->policy->resourcesEnabled()
                ? $this->handleListResources($id, $params)
                : JsonRpc::successResponse($id, ['resources' => []]);
            return $isNotification ? null : $response;
        }

        if ($method === 'resources/templates/list') {
            $response = $this->policy->resourcesEnabled()
                ? $this->handleListResourceTemplates($id, $params)
                : JsonRpc::successResponse($id, ['resourceTemplates' => []]);
            return $isNotification ? null : $response;
        }

        if ($method === 'resources/read') {
            $response = $this->policy->resourcesEnabled()
                ? $this->handleReadResource($id, $params)
                : JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, 'Resources are disabled by server policy');
            return $isNotification ? null : $response;
        }

        if ($method === 'prompts/list') {
            $response = $this->policy->promptsEnabled()
                ? $this->handleListPrompts($id, $params)
                : JsonRpc::successResponse($id, ['prompts' => []]);
            return $isNotification ? null : $response;
        }

        if ($method === 'prompts/get') {
            $response = $this->policy->promptsEnabled()
                ? $this->handleGetPrompt($id, $params)
                : JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, 'Prompts are disabled by server policy');
            return $isNotification ? null : $response;
        }

        if ($method === 'logging/setLevel') {
            return $isNotification ? null : JsonRpc::successResponse($id, new \stdClass());
        }

        if ($method === 'site_health') {
            $version = new JoomlaVersion();
            $response = JsonRpc::successResponse($id, [
                'status' => 'ok',
                'joomla_version' => $version->getShortVersion(),
                'timestamp' => (new \DateTimeImmutable('now'))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format(DATE_ATOM),
            ]);
            return $isNotification ? null : $response;
        }

        $response = JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, 'Requested method not implemented');
        return $isNotification ? null : $response;
    }

    private function handleCapabilities(mixed $id, array $params = []): array
    {
        $clientVersion = $params['protocolVersion'] ?? null;
        $negotiatedVersion = is_string($clientVersion) && in_array($clientVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $clientVersion
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];

        $capabilities = [
            'tools' => ['listChanged' => false],
        ];
        if ($this->policy->resourcesEnabled()) {
            $capabilities['resources'] = ['subscribe' => false, 'listChanged' => false];
        }
        if ($this->policy->promptsEnabled()) {
            $capabilities['prompts'] = ['listChanged' => false];
        }

        $instructions = 'List tool responses include a pagination object with has_more, next_offset, '
            . 'and total_count. When has_more is true, call the same tool again with offset set to '
            . 'next_offset (and the same limit if used) to retrieve the remaining items. '
            . 'For tools/list, follow nextCursor until it is absent to discover every available tool.';
        if ($this->policy->resourcesEnabled()) {
            $instructions .= ' Recent published articles are available as resources at joomla://article/{id}.';
        }
        if ($this->policy->promptsEnabled()) {
            $instructions .= ' Guided prompts: draft-article, seo-audit-article, translate-article.';
        }

        return JsonRpc::successResponse($id, [
            'protocolVersion' => $negotiatedVersion,
            'capabilities' => $capabilities,
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->getComponentVersion(),
            ],
            'instructions' => $instructions,
        ]);
    }

    private function getComponentVersion(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        $manifestPath = JPATH_ADMINISTRATOR . '/components/com_mcpserver/mcpserver.xml';

        if (is_file($manifestPath)) {
            $xml = @simplexml_load_file($manifestPath);
            if ($xml !== false && isset($xml->version)) {
                $version = trim((string) $xml->version);
                if ($version !== '') {
                    self::$cachedVersion = $version;
                    return self::$cachedVersion;
                }
            }
        }

        self::$cachedVersion = 'unknown';
        return self::$cachedVersion;
    }

    private function handleListTools(mixed $id, array $params = []): array
    {
        $tools = $this->toolRegistry->getAll();
        $response = $this->paginateListResult($id, $tools, $params, 'tools');

        if (!isset($response['error'])) {
            $page = $response['result']['tools'];
            $offset = 0;
            if (array_key_exists('cursor', $params) && is_string($params['cursor']) && $params['cursor'] !== '') {
                $offset = $this->decodeListCursor($params['cursor']) ?? 0;
            }
            $this->logger->info(
                'listTools: Found ' . count($tools) . ' tools, returning ' . count($page) . ' from offset ' . $offset,
                ['server' => $this->serverName]
            );
        }

        return $response;
    }

    private function encodeListCursor(int $offset): string
    {
        return base64_encode((string) json_encode(['offset' => $offset], JSON_THROW_ON_ERROR));
    }

    private function decodeListCursor(string $cursor): ?int
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['offset']) || !is_numeric($data['offset'])) {
            return null;
        }

        $offset = (int) $data['offset'];

        return $offset >= 0 ? $offset : null;
    }

    private function paginateListResult(mixed $id, array $items, array $params, string $itemsKey): array
    {
        $total = count($items);
        $offset = 0;

        if (array_key_exists('cursor', $params)) {
            if (!is_string($params['cursor']) || $params['cursor'] === '') {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Invalid cursor');
            }

            $decodedOffset = $this->decodeListCursor($params['cursor']);

            if ($decodedOffset === null) {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Invalid cursor');
            }

            $offset = $decodedOffset;
        }

        if ($offset > $total) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Invalid cursor');
        }

        if ($total <= $this->toolsListPageSize && $offset === 0) {
            $page = $items;
            $result = [$itemsKey => $page];
        } else {
            $page = array_values(array_slice($items, $offset, $this->toolsListPageSize));
            $result = [$itemsKey => $page];

            if ($offset + count($page) < $total) {
                $result['nextCursor'] = $this->encodeListCursor($offset + count($page));
            }
        }

        return JsonRpc::successResponse($id, $result);
    }

    private function handleListResources(mixed $id, array $params): array
    {
        $resources = array_map(
            fn (array $article): array => $this->mapArticleToResource($article),
            $this->fetchRecentArticles()
        );

        return $this->paginateListResult($id, $resources, $params, 'resources');
    }

    private function handleListResourceTemplates(mixed $id, array $params): array
    {
        $templates = [
            [
                'uriTemplate' => 'joomla://article/{id}',
                'name' => 'article',
                'title' => 'Joomla article',
                'description' => 'A published Joomla article by ID',
                'mimeType' => 'text/html',
            ],
        ];

        return $this->paginateListResult($id, $templates, $params, 'resourceTemplates');
    }

    private function handleReadResource(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? '';
        if (!is_string($uri) || $uri === '') {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Resource URI is required');
        }

        $articleId = $this->parseArticleResourceUri($uri);
        // Unknown resource → INVALID_PARAMS (-32602), NOT the MCP spec's
        // -32002 (resource not found). JsonRpc::RATE_LIMITED already occupies
        // -32002 and RpcHandlerTrait maps it to HTTP 429 + rate_limited metrics.
        if ($articleId === null) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Resource not found: ' . $uri);
        }

        try {
            $article = $this->getArticleById(['id' => $articleId]);
            $attributes = $article['data']['attributes'] ?? [];
            $text = (string) ($attributes['introtext'] ?? '') . (string) ($attributes['fulltext'] ?? '');

            return JsonRpc::successResponse($id, [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/html',
                        'text' => $text,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof RequestException && $e->hasResponse() && $e->getResponse()->getStatusCode() === 404) {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Resource not found: ' . $uri);
            }

            $this->logger->error('Resource read failed', [
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            $clientMessage = ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException)
                ? $e->getMessage()
                : 'Resource read failed due to an internal error';

            return JsonRpc::errorResponse($id, JsonRpc::INTERNAL_ERROR, $clientMessage);
        }
    }

    private function parseArticleResourceUri(string $uri): ?int
    {
        if (!preg_match('#^joomla://article/(\d+)$#', $uri, $matches)) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    /**
     * Cache key reuses the articles_search: prefix so existing
     * deleteByPrefix('articles_search:') calls in article write executors
     * invalidate this list with no extra wiring.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecentArticles(): array
    {
        return $this->cache->remember('articles_search:recent_resources', function () {
            $response = $this->rest->get('api/index.php/v1/content/articles', [
                'filter[state]' => 1,
                'page[limit]' => self::RESOURCES_ARTICLE_LIMIT,
            ]);

            $items = $response['data'] ?? [];
            if (!is_array($items) || $items === []) {
                return [];
            }

            if ($this->isAssociativeRecord($items)) {
                $items = [$items];
            }

            usort(
                $items,
                static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)
            );

            return $items;
        });
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private function mapArticleToResource(array $article): array
    {
        $id = (int) ($article['id'] ?? 0);
        $attributes = is_array($article['attributes'] ?? null) ? $article['attributes'] : [];
        $alias = trim((string) ($attributes['alias'] ?? ''));
        $intro = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($attributes['introtext'] ?? ''))) ?? '');
        // mb_substr: a byte-based cut can split a UTF-8 character, and one
        // malformed string makes json_encode() fail for the whole response.
        if (mb_strlen($intro) > 200) {
            $intro = mb_substr($intro, 0, 200);
        }

        return [
            'uri' => self::ARTICLE_RESOURCE_URI_PREFIX . $id,
            'name' => $alias !== '' ? $alias : 'article-' . $id,
            'title' => (string) ($attributes['title'] ?? ''),
            'description' => $intro,
            'mimeType' => 'text/html',
        ];
    }

    private function handleListPrompts(mixed $id, array $params): array
    {
        return $this->paginateListResult($id, $this->promptRegistry->getAll(), $params, 'prompts');
    }

    private function handleGetPrompt(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        if (!is_string($name) || $name === '') {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Prompt name is required');
        }

        $prompt = $this->promptRegistry->get($name);
        if ($prompt === null) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Unknown prompt: ' . $name);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $arguments = [];
        }

        foreach ($prompt['arguments'] ?? [] as $argument) {
            if (($argument['required'] ?? false) !== true) {
                continue;
            }
            $argName = (string) ($argument['name'] ?? '');
            $value = $arguments[$argName] ?? null;
            if (!is_string($value) || $value === '') {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Missing required argument: ' . $argName);
            }
        }

        try {
            return JsonRpc::successResponse($id, $this->promptRegistry->build($name, $arguments));
        } catch (\Throwable $e) {
            $this->logger->error('Prompt build failed', [
                'prompt' => $name,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \InvalidArgumentException) {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, $e->getMessage());
            }

            $clientMessage = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Prompt build failed due to an internal error';

            return JsonRpc::errorResponse($id, JsonRpc::INTERNAL_ERROR, $clientMessage);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function buildDraftArticlePrompt(array $arguments): array
    {
        $topic = (string) $arguments['topic'];
        $category = isset($arguments['category']) && is_string($arguments['category']) && $arguments['category'] !== ''
            ? $arguments['category']
            : null;

        $titles = [];
        foreach (array_slice($this->fetchRecentArticles(), 0, 5) as $article) {
            $title = (string) (($article['attributes']['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        $text = 'Draft a new Joomla article about "' . $topic . '"';
        if ($category !== null) {
            $text .= ' in category "' . $category . '"';
        }
        $text .= '.';
        if ($titles !== []) {
            $text .= ' Match the tone of these recent articles: ' . implode('; ', $titles) . '.';
        }
        $text .= ' Output HTML suitable for the create_article tool (introtext, and fulltext if needed).';

        return [
            'description' => 'Draft a new article about ' . $topic,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function buildSeoAuditPrompt(array $arguments): array
    {
        $articleId = (int) $arguments['article_id'];
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('article_id must be a positive integer');
        }

        $article = $this->getArticleById(['id' => $articleId]);
        $attributes = $article['data']['attributes'] ?? [];
        $uri = self::ARTICLE_RESOURCE_URI_PREFIX . $articleId;
        $body = (string) ($attributes['introtext'] ?? '') . (string) ($attributes['fulltext'] ?? '');
        $title = (string) ($attributes['title'] ?? '');
        $alias = (string) ($attributes['alias'] ?? '');
        $metadesc = (string) ($attributes['metadesc'] ?? '');

        return [
            'description' => 'SEO audit of article ' . $articleId,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'resource',
                        'resource' => [
                            'uri' => $uri,
                            'mimeType' => 'text/html',
                            'text' => $body,
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Audit this Joomla article for SEO.\n"
                            . "Title: {$title}\nAlias: {$alias}\nMeta description: {$metadesc}\n"
                            . 'Suggest concrete changes for the update_article tool (title, alias, metadesc, introtext/fulltext).',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function buildTranslateArticlePrompt(array $arguments): array
    {
        $articleId = (int) $arguments['article_id'];
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('article_id must be a positive integer');
        }

        $targetLanguage = (string) $arguments['target_language'];
        $article = $this->getArticleById(['id' => $articleId]);
        $attributes = $article['data']['attributes'] ?? [];
        $uri = self::ARTICLE_RESOURCE_URI_PREFIX . $articleId;
        $body = (string) ($attributes['introtext'] ?? '') . (string) ($attributes['fulltext'] ?? '');

        $languages = $this->listContentLanguages(['published' => 1]);
        $tags = [];
        foreach ($languages['data'] ?? [] as $language) {
            $attrs = is_array($language['attributes'] ?? null) ? $language['attributes'] : $language;
            $code = $attrs['lang_code'] ?? null;
            if (is_string($code) && $code !== '') {
                $tags[] = $code;
            }
        }

        $langList = $tags !== [] ? implode(', ', $tags) : '(none listed)';

        return [
            'description' => 'Translate article ' . $articleId . ' to ' . $targetLanguage,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'resource',
                        'resource' => [
                            'uri' => $uri,
                            'mimeType' => 'text/html',
                            'text' => $body,
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Translate this article into {$targetLanguage}. "
                            . "Published content-language tags on this site: {$langList}. "
                            . "Create the translation with create_article (set language to {$targetLanguage}), "
                            . 'then link it with set_article_associations.',
                    ],
                ],
            ],
        ];
    }

    private function handleCallTool(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $toolParams = $params['arguments'] ?? [];

        if (empty($toolName)) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Tool name is required');
        }

        if (!$this->policy->isToolAllowed($toolName)) {
            $this->lastCallBlocked = true;

            return JsonRpc::successResponse($id, $this->formatToolError(
                "Tool '{$toolName}' is disabled by server policy. Check the Disabled Tools list in MCP Server options."
            ));
        }

        $tool = $this->toolRegistry->get($toolName);
        if ($tool === null) {
            return JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, 'Tool not found');
        }

        if ($this->policy->isReadOnly() && ($tool['annotations']['readOnlyHint'] ?? false) !== true) {
            $this->lastCallBlocked = true;

            return JsonRpc::successResponse($id, $this->formatToolError(
                "Tool '{$toolName}' is blocked: the MCP server is in read-only mode. "
                . 'Disable Read-Only Mode in MCP Server options to allow write tools.'
            ));
        }

        if (isset($tool['inputSchema'])) {
            $validationError = $this->validator->validate($toolParams, $tool['inputSchema']);
            if ($validationError !== null) {
                return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'Invalid parameters: ' . $validationError);
            }
        }

        // API-only tools continue through the principal's own Joomla API token.
        // Direct/mixed executors bypass that API boundary, so governed requests
        // require an explicit Joomla ACL decision before any executor runs.
        // Anchored on the principal, not the authorizer: a missing authorizer
        // must deny, never grant. The constructor already rejects that pairing;
        // this keeps the guard correct even if that check is ever relaxed.
        if (
            $this->principal !== null
            && ($this->authorizer === null || !$this->authorizer->authorise($this->principal, $toolName, $toolParams))
        ) {
            $this->lastCallBlocked = true;

            return JsonRpc::successResponse($id, $this->formatToolError('Tool access is not authorized.'));
        }

        try {
            $result = $this->toolRegistry->execute($toolName, $toolParams);

            return JsonRpc::successResponse($id, $this->formatToolSuccess($result));
        } catch (\Throwable $e) {
            // Mark the call failed so the caller does not audit this as 'ok':
            // the response below is a JSON-RPC success envelope carrying an
            // MCP isError result, not a JSON-RPC error.
            $this->lastCallFailed = true;

            $this->logger->error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Surface our own deliberate validation/operation messages, which are
            // safe and actionable. For anything unexpected (PHP errors, DB driver
            // exceptions, Guzzle transport errors) return a generic message so
            // internal details — paths, SQL, upstream URLs — are not disclosed.
            $clientMessage = ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException)
                ? $e->getMessage()
                : 'Tool execution failed due to an internal error';

            return JsonRpc::successResponse($id, $this->formatToolError($clientMessage));
        }
    }

    private function getArticleById(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $cacheKey = 'article:' . $articleId;
        return $this->cache->remember($cacheKey, function () use ($articleId) {
            $response = $this->rest->get('api/index.php/v1/content/articles/' . $articleId);
            return $this->injectRawArticleContent($response);
        });
    }

    private function searchArticles(array $params): array
    {
        // Joomla's web services API only reads list filters from the filter[...]
        // query array (ArticlesController::displayList); bare params are ignored.
        // Note the API names: catid maps to filter[category] and author is a
        // numeric user id via filter[author].
        $filterMap = [
            'search'   => 'filter[search]',
            'language' => 'filter[language]',
            'catid'    => 'filter[category]',
            'state'    => 'filter[state]',
            'author'   => 'filter[author]',
            'featured' => 'filter[featured]',
        ];

        $query = [];
        foreach ($filterMap as $key => $filter) {
            if (isset($params[$key])) {
                $query[$filter] = $params[$key];
            }
        }
        $query = array_merge($query, $this->buildPageQuery($params));

        $cacheKey = 'articles_search:' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($query) {
                $response = $this->rest->get('api/index.php/v1/content/articles', $query);
                return $this->injectRawArticleContent($response);
            }),
            $params,
            'data',
            true
        );
    }

    /**
     * The Joomla web services API runs the content plugins against the response, which
     * strips/expands tags such as {loadmoduleid …}, {loadposition …} and {loadmodule …}.
     * Replace introtext/fulltext with the raw values from #__content so a read-modify-write
     * round-trip preserves these tags.
     */
    private function injectRawArticleContent(array $response): array
    {
        // Governed principals must only see the Joomla Web Services response
        // authorized by their own API token. Legacy shared-token operation
        // retains the existing raw-content round-trip behavior.
        if (!$this->allowRawArticleContent) {
            return $response;
        }

        $ids = [];
        if (isset($response['data']['id'])) {
            $ids[] = (int) $response['data']['id'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $row) {
                if (isset($row['id'])) {
                    $ids[] = (int) $row['id'];
                }
            }
        }

        $ids = array_filter($ids, static fn ($id) => $id > 0);
        if (empty($ids)) {
            return $response;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'introtext', 'fulltext']))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $ids);
        $rows = $db->setQuery($query)->loadAssocList('id');

        if (empty($rows)) {
            return $response;
        }

        $apply = static function (array &$item) use ($rows): void {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0 || !isset($rows[$id])) {
                return;
            }
            $item['attributes']['introtext'] = $rows[$id]['introtext'] ?? '';
            $item['attributes']['fulltext']  = $rows[$id]['fulltext'] ?? '';
        };

        if (isset($response['data']['id'])) {
            $apply($response['data']);
        } elseif (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as &$row) {
                $apply($row);
            }
            unset($row);
        }

        return $response;
    }

    private function createArticle(array $params): array
    {
        $payload = $this->normaliseArticlePayload((array) ($params['article'] ?? []));
        if (empty($payload)) {
            throw new \InvalidArgumentException('article object is required');
        }

        if (!isset($payload['language'])) {
            $payload['language'] = '*';
        }

        $result = $this->rest->post('api/index.php/v1/content/articles', $payload);
        $this->cache->deleteByPrefix('articles_search:');
        return $result;
    }

    private function updateArticle(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        $payload = $this->normaliseArticlePayload((array) ($params['article'] ?? []));
        if ($articleId <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and article are required');
        }

        $result = $this->rest->patch('api/index.php/v1/content/articles/' . $articleId, $payload);
        $this->cache->delete('article:' . $articleId);
        $this->cache->deleteByPrefix('articles_search:');
        return $result;
    }

    /**
     * Joomla's web services API only persists article content when supplied via "introtext"
     * (and optionally "fulltext"). Map common aliases so callers can't silently bump the
     * version without changing the body.
     */
    private function normaliseArticlePayload(array $payload): array
    {
        foreach (['articletext', 'text', 'content'] as $alias) {
            if (isset($payload[$alias]) && !isset($payload['introtext'])) {
                $payload['introtext'] = $payload[$alias];
            }
            unset($payload[$alias]);
        }

        return $payload;
    }

    private function deleteArticle(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        // Joomla only deletes trashed articles (ArticleModel::canDelete requires
        // state -2); a direct DELETE on anything else fails with a permissions
        // error. Trash the article first when needed.
        $current = $this->rest->get('api/index.php/v1/content/articles/' . $articleId);
        $state = (int) ($current['data']['attributes']['state'] ?? 0);
        if ($state !== -2) {
            $this->rest->patch('api/index.php/v1/content/articles/' . $articleId, ['state' => -2]);
        }

        $result = $this->rest->delete('api/index.php/v1/content/articles/' . $articleId);
        $this->cache->delete('article:' . $articleId);
        $this->cache->deleteByPrefix('articles_search:');
        return $result;
    }

    private function listArticleVersions(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $query = $this->buildPageQuery($params);

        $cacheKey = 'article_versions:' . $articleId . ':' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($articleId, $query) {
                return $this->rest->get(
                    'api/index.php/v1/content/articles/' . $articleId . '/contenthistory',
                    $query
                );
            }),
            $params,
            'data',
            true
        );
    }

    private function getArticleVersion(array $params): array
    {
        $versionId = (int) ($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new \InvalidArgumentException('version_id is required');
        }

        $cacheKey = 'article_version:' . $versionId;
        return $this->cache->remember($cacheKey, function () use ($versionId) {
            $row = $this->loadArticleVersionRow($versionId);
            $versionData = json_decode($row['version_data'] ?? '', true);
            if (!\is_array($versionData)) {
                $versionData = null;
            }

            return [
                'data' => [
                    'version_id' => (int) $row['version_id'],
                    'item_id' => $row['item_id'],
                    'version_note' => $row['version_note'],
                    'save_date' => $row['save_date'],
                    'editor_user_id' => (int) $row['editor_user_id'],
                    'editor' => $row['editor'] ?? null,
                    'character_count' => (int) $row['character_count'],
                    'sha1_hash' => $row['sha1_hash'],
                    'keep_forever' => (int) $row['keep_forever'],
                    'version_data' => $versionData,
                ],
            ];
        });
    }

    private function diffArticleVersions(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        $fromId = (int) ($params['version_id_from'] ?? 0);
        $toId = (int) ($params['version_id_to'] ?? 0);

        if ($articleId <= 0 || $fromId <= 0 || $toId <= 0) {
            throw new \InvalidArgumentException('id, version_id_from and version_id_to are required');
        }
        if ($fromId === $toId) {
            throw new \InvalidArgumentException('version_id_from and version_id_to must differ');
        }

        $fromRow = $this->loadArticleVersionRow($fromId);
        $toRow = $this->loadArticleVersionRow($toId);
        $expectedItemId = self::ARTICLE_VERSION_ITEM_ID_PREFIX . $articleId;

        if (($fromRow['item_id'] ?? '') !== $expectedItemId) {
            throw new \InvalidArgumentException('version_id_from does not belong to the specified article');
        }
        if (($toRow['item_id'] ?? '') !== $expectedItemId) {
            throw new \InvalidArgumentException('version_id_to does not belong to the specified article');
        }

        $identical = (string) ($fromRow['sha1_hash'] ?? '') !== ''
            && (string) ($fromRow['sha1_hash'] ?? '') === (string) ($toRow['sha1_hash'] ?? '');

        $changes = [];
        if (!$identical) {
            $fromData = json_decode($fromRow['version_data'] ?? '', true);
            $toData = json_decode($toRow['version_data'] ?? '', true);
            if (!\is_array($fromData) || !\is_array($toData)) {
                throw new \RuntimeException('Version data is missing or invalid');
            }
            $changes = TextDiff::diffFields($fromData, $toData, self::ARTICLE_RESTORABLE_FIELDS);
        }

        return [
            'data' => [
                'id' => $articleId,
                'version_id_from' => $fromId,
                'version_id_to' => $toId,
                'identical' => $identical,
                'changes' => $changes,
                'from' => [
                    'save_date' => $fromRow['save_date'] ?? null,
                    'version_note' => $fromRow['version_note'] ?? null,
                    'editor' => $fromRow['editor'] ?? null,
                ],
                'to' => [
                    'save_date' => $toRow['save_date'] ?? null,
                    'version_note' => $toRow['version_note'] ?? null,
                    'editor' => $toRow['editor'] ?? null,
                ],
            ],
        ];
    }

    private function keepArticleVersion(array $params): array
    {
        $versionId = (int) ($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new \InvalidArgumentException('version_id is required');
        }

        $row = $this->loadArticleVersionRow($versionId);
        // Joomla's contenthistory routes reuse :id with different semantics per verb:
        // GET uses the article ID; PATCH keep and DELETE use the #__history version_id.
        $result = $this->rest->patch(
            $this->articleVersionMutationPath($versionId, 'keep'),
            []
        );
        $this->invalidateArticleVersionCaches($row);
        return $result;
    }

    private function deleteArticleVersion(array $params): array
    {
        $versionId = (int) ($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new \InvalidArgumentException('version_id is required');
        }

        $row = $this->loadArticleVersionRow($versionId);
        if ((int) ($row['keep_forever'] ?? 0) === 1) {
            throw new \InvalidArgumentException('Cannot delete a version marked keep forever');
        }

        $result = $this->rest->delete(
            $this->articleVersionMutationPath($versionId)
        );
        $this->invalidateArticleVersionCaches($row);
        $this->cache->delete('article_version:' . $versionId);
        return $result;
    }

    private function restoreArticleVersion(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        $versionId = (int) ($params['version_id'] ?? 0);
        if ($articleId <= 0 || $versionId <= 0) {
            throw new \InvalidArgumentException('id and version_id are required');
        }

        $row = $this->loadArticleVersionRow($versionId);
        $expectedItemId = self::ARTICLE_VERSION_ITEM_ID_PREFIX . $articleId;
        if (($row['item_id'] ?? '') !== $expectedItemId) {
            throw new \InvalidArgumentException('version_id does not belong to the specified article');
        }

        $versionData = json_decode($row['version_data'] ?? '', true);
        if (!\is_array($versionData)) {
            throw new \RuntimeException('Version data is missing or invalid');
        }

        $payload = $this->buildArticleRestorePayload($versionData);
        if (isset($params['version_note']) && $params['version_note'] !== '') {
            $payload['version_note'] = (string) $params['version_note'];
        }

        $result = $this->rest->patch('api/index.php/v1/content/articles/' . $articleId, $payload);
        $this->cache->delete('article:' . $articleId);
        $this->cache->deleteByPrefix('articles_search:');
        $this->invalidateArticleVersionCaches($row);
        return $this->injectRawArticleContent($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadArticleVersionRow(int $versionId): array
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('h.version_id'),
                $db->quoteName('h.item_id'),
                $db->quoteName('h.version_note'),
                $db->quoteName('h.save_date'),
                $db->quoteName('h.editor_user_id'),
                $db->quoteName('h.character_count'),
                $db->quoteName('h.sha1_hash'),
                $db->quoteName('h.version_data'),
                $db->quoteName('h.keep_forever'),
                $db->quoteName('uc.name', 'editor'),
            ])
            ->from($db->quoteName('#__history', 'h'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'uc'),
                $db->quoteName('uc.id') . ' = ' . $db->quoteName('h.editor_user_id')
            )
            ->where($db->quoteName('h.version_id') . ' = ' . (int) $versionId)
            ->where($db->quoteName('h.item_id') . ' LIKE ' . $db->quote(self::ARTICLE_VERSION_ITEM_ID_PREFIX . '%'));
        $row = $db->setQuery($query)->loadAssoc();

        if (empty($row)) {
            throw new \InvalidArgumentException('Article version not found');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $versionData
     * @return array<string, mixed>
     */
    private function buildArticleRestorePayload(array $versionData): array
    {
        $payload = [];
        foreach (self::ARTICLE_RESTORABLE_FIELDS as $field) {
            if (array_key_exists($field, $versionData)) {
                $payload[$field] = $versionData[$field];
            }
        }

        return $this->normaliseArticlePayload($payload);
    }

    private function articleVersionMutationPath(int $versionId, ?string $action = null): string
    {
        $path = 'api/index.php/v1/content/articles/' . $versionId . '/contenthistory';
        if ($action !== null) {
            $path .= '/' . $action;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function invalidateArticleVersionCaches(array $row): void
    {
        $itemId = (string) ($row['item_id'] ?? '');
        $prefix = self::ARTICLE_VERSION_ITEM_ID_PREFIX;
        if (str_starts_with($itemId, $prefix)) {
            $articleId = (int) substr($itemId, \strlen($prefix));
            if ($articleId > 0) {
                $this->cache->deleteByPrefix('article_versions:' . $articleId . ':');
            }
        }

        if (isset($row['version_id'])) {
            $this->cache->delete('article_version:' . (int) $row['version_id']);
        }
    }

    private function createCustomModule(array $params): array
    {
        if (($params['title'] ?? '') === '' || ($params['content'] ?? '') === '' || ($params['position'] ?? '') === '') {
            throw new \InvalidArgumentException('title, content and position are required');
        }

        return $this->insertModule('mod_custom', $params);
    }

    private function createModule(array $params): array
    {
        if (($params['title'] ?? '') === '' || ($params['module'] ?? '') === '' || ($params['position'] ?? '') === '') {
            throw new \InvalidArgumentException('title, module and position are required');
        }

        return $this->insertModule((string) $params['module'], $params);
    }

    /**
     * Insert a module row directly — Joomla's modules API exposes no create route.
     */
    private function insertModule(string $moduleElement, array $params): array
    {
        $client = $params['client'] ?? 'site';
        $clientId = $client === 'administrator' ? 1 : 0;

        $db = Factory::getDbo();

        // The element must be an installed module type for this client; an
        // arbitrary value would create a row that renders nothing.
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('module'))
            ->where($db->quoteName('element') . ' = ' . $db->quote($moduleElement))
            ->where($db->quoteName('client_id') . ' = ' . $clientId);
        if (!$db->setQuery($query)->loadResult()) {
            throw new \InvalidArgumentException(
                "Module type '{$moduleElement}' is not installed for the {$client} client"
            );
        }

        $module = new \stdClass();
        $module->title     = (string) $params['title'];
        $module->module    = $moduleElement;
        $module->position  = (string) ($params['position'] ?? '');
        $module->published = (int) ($params['published'] ?? 1);
        $module->access    = (int) ($params['access'] ?? 1);
        $module->language  = $params['language'] ?? '*';
        $module->client_id = $clientId;
        $module->content   = (string) ($params['content'] ?? '');
        $module->params    = isset($params['params']) && is_array($params['params'])
            ? (string) json_encode($params['params'])
            : '{}';
        $module->showtitle = (int) ($params['showtitle'] ?? 1);
        $module->ordering  = (int) ($params['ordering'] ?? 0);
        $module->note      = (string) ($params['note'] ?? '');

        $db->insertObject('#__modules', $module, 'id');
        $moduleId = (int) $module->id;

        if ($moduleId <= 0) {
            throw new \RuntimeException('Failed to create module');
        }

        // Assign to all pages (menuid 0).
        $mapping = new \stdClass();
        $mapping->moduleid = $moduleId;
        $mapping->menuid   = 0;
        $db->insertObject('#__modules_menu', $mapping);

        $this->cache->deleteByPrefix('modules_list:');
        $this->cache->deleteByPrefix('all_modules_list:');

        $path = $client === 'administrator'
            ? 'api/index.php/v1/modules/administrator/'
            : 'api/index.php/v1/modules/site/';

        return $this->rest->get($path . $moduleId);
    }

    private function deleteModule(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $clientId = $client === 'administrator' ? 1 : 0;
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'module']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('client_id') . ' = ' . $clientId);
        $existing = $db->setQuery($query)->loadObject();

        if (!$existing) {
            throw new \InvalidArgumentException('Module ' . $id . ' not found');
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__modules_menu'))
                ->where($db->quoteName('moduleid') . ' = ' . $id)
        )->execute();

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__modules'))
                ->where($db->quoteName('id') . ' = ' . $id)
        )->execute();

        $this->cache->delete('module:' . $client . ':' . $id);
        $this->cache->deleteByPrefix('modules_list:');
        $this->cache->deleteByPrefix('all_modules_list:');

        return [
            'data' => [
                'id'      => $id,
                'title'   => (string) $existing->title,
                'module'  => (string) $existing->module,
                'deleted' => true,
            ],
        ];
    }

    private function listCustomModules(array $params): array
    {
        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator' ? 'api/index.php/v1/modules/administrator' : 'api/index.php/v1/modules/site';

        // Aggregate every upstream page before filtering: the type filter runs
        // locally, so fetching only the API's first page (default 20 rows) would
        // silently hide custom modules on sites with more modules than that.
        $cacheKey = 'modules_list:' . $client;
        $modules = $this->cache->remember($cacheKey, function () use ($path) {
            return ['data' => $this->fetchAllPages($path)];
        });

        // Filter for mod_custom
        if (isset($modules['data'])) {
            $modules['data'] = array_values(array_filter($modules['data'], function ($item) {
                return ($item['attributes']['module'] ?? '') === 'mod_custom';
            }));
        }

        return $this->withPaginationMetadata($modules, $params);
    }

    /**
     * Fetch every page of a paginated Joomla API collection. The API returns at
     * most one page (default 20 rows) per request, so tools that filter or list
     * the complete set locally must walk all pages.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllPages(string $path, array $query = [], int $pageSize = 100, int $maxPages = 50): array
    {
        $items = [];
        $offset = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->rest->get($path, array_merge($query, [
                'page[limit]'  => $pageSize,
                'page[offset]' => $offset,
            ]));

            $data = $response['data'] ?? [];
            if (!is_array($data) || $data === [] || isset($data['id'])) {
                break;
            }

            $items = array_merge($items, array_values($data));

            $count = count($data);
            $offset += $count;

            if ($count < $pageSize) {
                break;
            }
        }

        return $items;
    }

    private function getCustomModuleById(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';
        
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $path = $client === 'administrator' ? 'api/index.php/v1/modules/administrator/' : 'api/index.php/v1/modules/site/';
        $cacheKey = 'module:' . $client . ':' . $id;

        return $this->cache->remember($cacheKey, function () use ($path, $id) {
            return $this->rest->get($path . $id);
        });
    }

    private function updateCustomModule(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $content = $params['content'] ?? null;
        $client = $params['client'] ?? 'site';

        if ($id <= 0 || $content === null) {
            throw new \InvalidArgumentException('id and content are required');
        }

        $db = Factory::getDbo();

        // Confirm the module exists, belongs to the requested client and really is
        // a mod_custom module before writing — an unconditional update would
        // silently clobber the content column of any module type.
        $clientId = $client === 'administrator' ? 1 : 0;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'module']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('client_id') . ' = ' . $clientId);
        $existing = $db->setQuery($query)->loadObject();

        if (!$existing) {
            throw new \InvalidArgumentException('Module ' . $id . ' not found');
        }

        if ($existing->module !== 'mod_custom') {
            throw new \InvalidArgumentException(
                'Module ' . $id . ' is a ' . $existing->module . ' module, not mod_custom; use update_module instead'
            );
        }

        $module = new \stdClass();
        $module->id      = $id;
        $module->content = $content;

        $db->updateObject('#__modules', $module, 'id');

        $this->cache->delete('module:' . $client . ':' . $id);
        $this->cache->delete('modules_list:' . $client);
        $this->cache->deleteByPrefix('all_modules_list:');

        $path = $client === 'administrator'
            ? 'api/index.php/v1/modules/administrator/'
            : 'api/index.php/v1/modules/site/';

        return $this->rest->get($path . $id);
    }

    private function listModules(array $params): array
    {
        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator'
            ? 'api/index.php/v1/modules/administrator'
            : 'api/index.php/v1/modules/site';

        $query = $this->buildPageQuery($params);

        $cacheKey = 'all_modules_list:' . $client . ':' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($path, $query) {
                return $this->rest->get($path, $query);
            }),
            $params,
            'data',
            true
        );
    }

    private function getModuleById(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $path = $client === 'administrator'
            ? 'api/index.php/v1/modules/administrator/'
            : 'api/index.php/v1/modules/site/';

        $cacheKey = 'module:' . $client . ':' . $id;
        return $this->cache->remember($cacheKey, function () use ($path, $id) {
            return $this->rest->get($path . $id);
        });
    }

    private function updateModule(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $db = Factory::getDbo();

        // #__modules uses client_id to distinguish site (0) from administrator (1)
        // modules, so filter on it to avoid loading/updating the wrong module.
        $clientId = $client === 'administrator' ? 1 : 0;

        // Load the existing row so we can merge params and confirm the module exists.
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('client_id') . ' = ' . $clientId);
        $existing = $db->setQuery($query)->loadObject();

        if (!$existing) {
            throw new \InvalidArgumentException('Module ' . $id . ' not found');
        }

        $module = new \stdClass();
        $module->id = $id;

        // Optional scalar fields: only those supplied are changed. The cast type
        // mirrors the matching #__modules column.
        $stringFields = ['title', 'position', 'content', 'language', 'note'];
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $params)) {
                $module->$field = (string) $params[$field];
            }
        }

        $intFields = ['published', 'access', 'showtitle', 'ordering'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $params)) {
                $module->$field = (int) $params[$field];
            }
        }

        // Merge type-specific params into the existing JSON (read-modify-write) so
        // callers only send the keys they want to change.
        if (array_key_exists('params', $params) && is_array($params['params'])) {
            $current = json_decode((string) $existing->params, true);
            if (!is_array($current)) {
                $current = [];
            }

            $module->params = json_encode(array_merge($current, $params['params']));
        }

        // Menu (page) assignment lives in #__modules_menu, not in the module row.
        // Resolve it before any write so an invalid assignment cannot leave the
        // scalar columns updated and the assignment half-applied.
        $assignmentSupplied = array_key_exists('assignment', $params) || array_key_exists('assigned', $params);
        $menuIds = $assignmentSupplied ? $this->resolveModuleMenuAssignment($db, $client, $params) : [];

        $hasColumnChanges = count(get_object_vars($module)) > 1;

        // Bail out if there is nothing to change beyond the id.
        if (!$hasColumnChanges && !$assignmentSupplied) {
            throw new \InvalidArgumentException('No updatable fields supplied');
        }

        if ($hasColumnChanges) {
            $db->updateObject('#__modules', $module, 'id');
        }

        if ($assignmentSupplied) {
            $this->writeModuleMenuAssignment($db, $id, $menuIds);
        }

        $this->cache->delete('module:' . $client . ':' . $id);
        $this->cache->delete('modules_list:' . $client);
        $this->cache->deleteByPrefix('all_modules_list:');

        $path = $client === 'administrator'
            ? 'api/index.php/v1/modules/administrator/'
            : 'api/index.php/v1/modules/site/';

        return $this->rest->get($path . $id);
    }

    /**
     * Translate the supplied assignment/assigned arguments into the #__modules_menu
     * menuids Joomla expects, validating them before anything is written: 0 = every
     * page (a single row with menuid 0), 1 = only the listed menu items (positive
     * menuids), -1 = every page except them (negative menuids), "-" = no page at all
     * (no rows). This mirrors ModuleModel::save() in com_modules, sign included, so a
     * module assigned here behaves exactly like one saved in the administrator.
     *
     * Combinations core silently reinterprets are rejected instead: it turns
     * assignment -1 with an empty list into "all pages" and assignment 1 with an empty
     * list into "no pages", neither of which is what such a call asked for.
     *
     * @param   array<string, mixed>  $params
     *
     * @return  list<int>  The menuids to store; an empty list means "no pages".
     */
    private function resolveModuleMenuAssignment(DatabaseInterface $db, string $client, array $params): array
    {
        // #__modules_menu is only meaningful for site modules: ModuleHelper matches
        // "menuid = Itemid OR menuid <= 0" with Itemid 0 in the administrator, so a
        // positive menuid would simply hide an administrator module from the backend.
        if ($client === 'administrator') {
            throw new \InvalidArgumentException(
                'Menu assignment applies to site modules only; administrator modules are not assigned to menu items'
            );
        }

        if (!array_key_exists('assignment', $params)) {
            throw new \InvalidArgumentException(
                'assignment is required when assigned is supplied (0 = all pages, 1 = only the selected pages, '
                . '-1 = all pages except the selected pages, "-" = no pages)'
            );
        }

        $assignment = $params['assignment'];
        if (is_string($assignment) && is_numeric($assignment)) {
            $assignment = (int) $assignment;
        }

        if (!in_array($assignment, [0, 1, -1, '-'], true)) {
            throw new \InvalidArgumentException(
                'assignment must be 0 (all pages), 1 (only the selected pages), -1 (all pages except the selected '
                . 'pages) or "-" (no pages)'
            );
        }

        $assigned = $params['assigned'] ?? [];
        if (!is_array($assigned)) {
            throw new \InvalidArgumentException('assigned must be an array of menu item IDs');
        }

        if ($assignment === 0 || $assignment === '-') {
            if ($assigned !== []) {
                throw new \InvalidArgumentException(
                    'assigned cannot be combined with assignment ' . ($assignment === 0 ? '0' : '"-"')
                    . '; use assignment 1 or -1 to select menu items'
                );
            }

            return $assignment === 0 ? [0] : [];
        }

        // Joomla stores the excluded ids of an "all except" assignment as negative
        // menuids and get_module_by_id reports them that way, so accept either sign
        // and apply the mode's own — feeding a module's own values back is then safe.
        $menuIds = [];
        foreach ($assigned as $itemId) {
            $itemId = abs((int) $itemId);

            if ($itemId === 0) {
                throw new \InvalidArgumentException('assigned must contain menu item IDs greater than zero');
            }

            $menuIds[$itemId] = $assignment * $itemId;
        }

        if ($menuIds === []) {
            throw new \InvalidArgumentException(
                'assigned must list at least one menu item ID when assignment is ' . $assignment
            );
        }

        $this->assertSiteMenuItemsExist($db, array_keys($menuIds));

        return array_values($menuIds);
    }

    /**
     * Reject menu item ids that are not site menu items. Joomla would store such a row
     * happily and the module would simply never appear, which is hard to tell apart
     * from a rendering problem.
     *
     * @param  list<int>  $itemIds
     */
    private function assertSiteMenuItemsExist(DatabaseInterface $db, array $itemIds): void
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('id') . ' IN (' . implode(', ', $itemIds) . ')')
            ->where($db->quoteName('client_id') . ' = 0');

        $found = array_map('intval', (array) $db->setQuery($query)->loadColumn());
        $missing = array_values(array_diff($itemIds, $found));

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                count($missing) === 1
                    ? 'Menu item ' . $missing[0] . ' does not exist as a site menu item'
                    : 'Menu items ' . implode(', ', $missing) . ' do not exist as site menu items'
            );
        }
    }

    /**
     * Replace a module's page assignment rows. Joomla derives the assignment mode from
     * the sign of the stored menuids, so the set is rewritten wholesale — a merge
     * could leave rows of two different modes behind and render the module twice.
     *
     * @param  list<int>  $menuIds
     */
    private function writeModuleMenuAssignment(DatabaseInterface $db, int $moduleId, array $menuIds): void
    {
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__modules_menu'))
                ->where($db->quoteName('moduleid') . ' = ' . $moduleId)
        )->execute();

        if ($menuIds === []) {
            return;
        }

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__modules_menu'))
            ->columns($db->quoteName(['moduleid', 'menuid']));

        foreach ($menuIds as $menuId) {
            $query->values($moduleId . ', ' . $menuId);
        }

        $db->setQuery($query)->execute();
    }

    private function listMenus(array $params): array
    {
        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator'
            : 'api/index.php/v1/menus/site';

        $query = $this->buildPageQuery($params);

        $cacheKey = 'menus_list:' . $client . ':' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($path, $query) {
                return $this->rest->get($path, $query);
            }),
            $params,
            'data',
            true
        );
    }

    private function listMenuItems(array $params): array
    {
        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator/items'
            : 'api/index.php/v1/menus/site/items';

        $query = [];
        if (isset($params['menutype'])) {
            $query['menutype'] = $params['menutype'];
        }
        $query = array_merge($query, $this->buildPageQuery($params));

        $cacheKey = 'menu_items:' . $client . ':' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($path, $query) {
                return $this->rest->get($path, $query);
            }),
            $params,
            'data',
            true
        );
    }

    private function getMenuItem(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator/items/'
            : 'api/index.php/v1/menus/site/items/';

        $cacheKey = 'menu_item:' . $client . ':' . $id;
        return $this->cache->remember($cacheKey, function () use ($path, $id) {
            return $this->rest->get($path . $id);
        });
    }

    private function createMenuItem(array $params): array
    {
        $title = $params['title'] ?? '';
        $menutype = $params['menutype'] ?? '';
        $type = $params['type'] ?? 'component';

        if ($title === '' || $menutype === '') {
            throw new \InvalidArgumentException('title and menutype are required');
        }

        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator/items'
            : 'api/index.php/v1/menus/site/items';

        $payload = [
            'title' => $title,
            'menutype' => $menutype,
            'type' => $type,
            'parent_id' => (int) ($params['parent_id'] ?? 1),
            'published' => (int) ($params['published'] ?? 1),
            'access' => (int) ($params['access'] ?? 1),
            'language' => $params['language'] ?? '*',
            'browserNav' => (int) ($params['browserNav'] ?? 0),
            'home' => (int) ($params['home'] ?? 0),
        ];

        foreach (['link', 'alias', 'note'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = $params[$key];
            }
        }

        if (isset($params['component_id'])) {
            $payload['component_id'] = (int) $params['component_id'];
        }

        if (isset($params['params'])) {
            $payload['params'] = (object) $params['params'];
        }

        $request = isset($params['request']) ? (array) $params['request'] : $this->extractRequestFromLink($payload['link'] ?? '');
        if (!empty($request)) {
            $payload['request'] = (object) $request;
        }

        $result = $this->rest->post($path, $payload);
        $this->cache->deleteByPrefix('menu_items:');
        return $result;
    }

    private function extractRequestFromLink(string $link): array
    {
        if ($link === '' || !str_contains($link, '?')) {
            return [];
        }

        $query = parse_url($link, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        unset($parsed['option'], $parsed['view'], $parsed['layout'], $parsed['Itemid']);

        return $parsed;
    }

    private function updateMenuItem(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $payload = (array) ($params['menu_item'] ?? []);
        $client = $params['client'] ?? 'site';

        if ($id <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and menu_item are required');
        }

        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator/items/'
            : 'api/index.php/v1/menus/site/items/';

        // Joomla's menu item PATCH endpoint reads fields such as menutype and
        // menuordering directly from the request body without merging with the
        // stored record. Sending a partial payload (e.g. only parent_id) causes
        // the nested-set rebuild to fail with a 500. Pre-load the existing item
        // and merge the caller's changes on top to send a complete payload.
        $existing = $this->rest->get($path . $id);
        $existingAttributes = $existing['data']['attributes'] ?? [];

        $writable = [
            'title', 'alias', 'menutype', 'type', 'link', 'parent_id', 'published',
            'access', 'language', 'browserNav', 'home', 'note', 'component_id',
            'params', 'request', 'template_style_id', 'publish_up', 'publish_down',
            'menuordering',
        ];

        $merged = [];
        foreach ($writable as $field) {
            if (array_key_exists($field, $existingAttributes)) {
                $merged[$field] = $existingAttributes[$field];
            }
        }

        foreach ($payload as $key => $value) {
            $merged[$key] = $value;
        }

        if (isset($merged['params'])) {
            $merged['params'] = (object) $merged['params'];
        }

        if (isset($merged['request'])) {
            $merged['request'] = (object) $merged['request'];
        }

        $result = $this->rest->patch($path . $id, $merged);
        $this->cache->delete('menu_item:' . $client . ':' . $id);
        $this->cache->deleteByPrefix('menu_items:');
        return $result;
    }

    private function createMenu(array $params): array
    {
        $title = (string) ($params['title'] ?? '');
        $menutype = (string) ($params['menutype'] ?? '');

        if ($title === '' || $menutype === '') {
            throw new \InvalidArgumentException('title and menutype are required');
        }

        $client = $params['client'] ?? 'site';
        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator'
            : 'api/index.php/v1/menus/site';

        $result = $this->rest->post($path, [
            'title'       => $title,
            'menutype'    => $menutype,
            'description' => (string) ($params['description'] ?? ''),
        ]);
        $this->cache->deleteByPrefix('menus_list:');
        return $result;
    }

    private function deleteMenuItem(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $path = $client === 'administrator'
            ? 'api/index.php/v1/menus/administrator/items/'
            : 'api/index.php/v1/menus/site/items/';

        // Joomla only deletes trashed menu items (ItemModel::canDelete requires
        // published -2). Trash through updateMenuItem, which already handles the
        // API's complete-payload PATCH requirement.
        $current = $this->rest->get($path . $id);
        if ((int) ($current['data']['attributes']['published'] ?? 0) !== -2) {
            $this->updateMenuItem([
                'id'        => $id,
                'client'    => $client,
                'menu_item' => ['published' => -2],
            ]);
        }

        $result = $this->rest->delete($path . $id);
        $this->cache->delete('menu_item:' . $client . ':' . $id);
        $this->cache->deleteByPrefix('menu_items:');
        return $result;
    }

    private function listMedia(array $params): array
    {
        $query = [];
        if (isset($params['path']) && $params['path'] !== '') {
            $query['path'] = (string) $params['path'];
        }
        if (isset($params['search']) && $params['search'] !== '') {
            $query['search'] = (string) $params['search'];
        }
        if (($params['include_url'] ?? true)) {
            $query['url'] = 1;
        }
        if (!empty($params['include_temp'])) {
            $query['temp'] = 1;
        }

        $cacheKey = 'media_list:' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($query) {
                return $this->rest->get('api/index.php/v1/media/files', $query);
            }),
            $params
        );
    }

    private function getMedia(array $params): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $query = [];
        if (($params['include_url'] ?? true)) {
            $query['url'] = 1;
        }
        if (!empty($params['include_content'])) {
            $query['content'] = 1;
        }

        $cacheKey = 'media_item:' . md5($path . '|' . json_encode($query));
        return $this->cache->remember($cacheKey, function () use ($path, $query) {
            return $this->rest->get('api/index.php/v1/media/files/' . $this->mediaItemPath($path), $query);
        });
    }

    private function uploadMedia(array $params): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $content = $this->resolveMediaContent($params);
        if ($content === null) {
            throw new \InvalidArgumentException('Either content or source_url is required');
        }

        $result = $this->rest->post('api/index.php/v1/media/files', [
            'path' => $path,
            'content' => $content,
        ]);
        $this->cache->deleteByPrefix('media_');
        return $result;
    }

    private function createMediaFolder(array $params): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $result = $this->rest->post('api/index.php/v1/media/files', [
            'path' => $path,
        ]);
        $this->cache->deleteByPrefix('media_');
        return $result;
    }

    private function updateMedia(array $params): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $payload = [];
        if (isset($params['new_path']) && $params['new_path'] !== '') {
            $payload['path'] = (string) $params['new_path'];
        }

        $content = $this->resolveMediaContent($params);
        if ($content !== null) {
            $payload['content'] = $content;
        }

        if (empty($payload)) {
            throw new \InvalidArgumentException('Provide at least one of new_path, content or source_url');
        }

        $result = $this->rest->patch('api/index.php/v1/media/files/' . $this->mediaItemPath($path), $payload);
        $this->cache->deleteByPrefix('media_');
        return $result;
    }

    private function deleteMedia(array $params): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $result = $this->rest->delete('api/index.php/v1/media/files/' . $this->mediaItemPath($path));
        $this->cache->deleteByPrefix('media_');
        return $result;
    }

    private function resolveMediaContent(array $params): ?string
    {
        $hasContent = isset($params['content']) && $params['content'] !== '';
        $hasUrl = isset($params['source_url']) && $params['source_url'] !== '';

        if ($hasContent && $hasUrl) {
            throw new \InvalidArgumentException('Provide either content or source_url, not both');
        }

        if ($hasContent) {
            return (string) $params['content'];
        }

        if ($hasUrl) {
            return base64_encode($this->rest->fetchUrlContent((string) $params['source_url']));
        }

        return null;
    }

    private function mediaItemPath(string $path): string
    {
        $adapter = '';
        if (preg_match('/^([A-Za-z0-9_\-]+:)(.*)$/', $path, $matches)) {
            $adapter = $matches[1];
            $path = $matches[2];
        }

        $segments = array_map(
            static fn ($segment) => rawurlencode($segment),
            explode('/', $path)
        );

        return $adapter . implode('/', $segments);
    }

    /**
     * Core Joomla declares exactly six custom field contexts (FieldsServiceInterface::getContexts()
     * in com_content, com_contact and com_users) and the webservices plugins route every one of
     * them, so the whole com_fields family stays on the Web Services API and inherits Joomla's ACL.
     */
    private const FIELD_CONTEXT_ROUTES = [
        'com_content.article'    => 'content/articles',
        'com_content.categories' => 'content/categories',
        'com_contact.contact'    => 'contacts/contact',
        'com_contact.mail'       => 'contacts/mail',
        'com_contact.categories' => 'contacts/categories',
        'com_users.user'         => 'users',
    ];

    /**
     * There is no plg_webservices_fields: each component's own webservices plugin registers the
     * com_fields routes for its contexts. A 404 on a fields collection therefore means a disabled
     * plugin far more often than a missing record, so name the plugin the admin has to enable.
     */
    private const FIELD_CONTEXT_PLUGINS = [
        'com_content' => 'plg_webservices_content',
        'com_contact' => 'plg_webservices_contact',
        'com_users'   => 'plg_webservices_users',
    ];

    /**
     * Field values live on the owning item's own resource, not on the fields endpoint, and only
     * the controllers implementing preprocessSaveData() can write them. com_categories does not,
     * so category field values are readable but not writable; com_contact.mail is the contact form
     * and stores no items at all, so it is absent entirely.
     */
    private const FIELD_VALUE_ITEM_ROUTES = [
        'com_content.article'    => ['path' => 'api/index.php/v1/content/articles', 'writable' => true],
        'com_content.categories' => ['path' => 'api/index.php/v1/content/categories', 'writable' => false],
        'com_contact.contact'    => ['path' => 'api/index.php/v1/contacts', 'writable' => true],
        'com_users.user'         => ['path' => 'api/index.php/v1/users', 'writable' => true],
    ];

    /** Scalar #__fields columns update_field copies straight through, by cast. */
    private const FIELD_STRING_COLUMNS = ['title', 'name', 'label', 'type', 'description', 'note', 'default_value', 'language'];
    private const FIELD_INT_COLUMNS = ['group_id', 'required', 'only_use_in_subform', 'state', 'access', 'ordering'];
    private const FIELD_GROUP_STRING_COLUMNS = ['title', 'description', 'note', 'language'];
    private const FIELD_GROUP_INT_COLUMNS = ['state', 'access', 'ordering'];

    private const CONTENT_LANGUAGES_PATH = 'api/index.php/v1/languages';
    private const CATEGORIES_PATH = 'api/index.php/v1/content/categories';
    private const TAGS_PATH = 'api/index.php/v1/tags';
    private const ASSOC_CONTEXT_ARTICLE = 'com_content.item';
    private const ASSOC_CONTEXT_MENU_ITEM = 'com_menus.item';
    private const ARTICLE_VERSION_ITEM_ID_PREFIX = 'com_content.article.';
    private const ARTICLE_RESTORABLE_FIELDS = [
        'title',
        'alias',
        'introtext',
        'fulltext',
        'catid',
        'language',
        'state',
        'access',
        'featured',
        'images',
        'urls',
        'attribs',
        'metakey',
        'metadesc',
        'metadata',
        'publish_up',
        'publish_down',
    ];

    private function listContentLanguages(array $params): array
    {
        $query = [];
        if (isset($params['published'])) {
            $query['filter[published]'] = (int) $params['published'];
        }
        $query = array_merge($query, $this->buildPageQuery($params));

        $cacheKey = 'content_languages:' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, fn () => $this->rest->get(self::CONTENT_LANGUAGES_PATH, $query)),
            $params,
            'data',
            true
        );
    }

    private function getContentLanguage(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $cacheKey = 'content_language:' . $id;
        return $this->cache->remember($cacheKey, fn () => $this->rest->get(self::CONTENT_LANGUAGES_PATH . '/' . $id));
    }

    private function createContentLanguage(array $params): array
    {
        $payload = $this->buildContentLanguagePayload($params, true);
        $result = $this->rest->post(self::CONTENT_LANGUAGES_PATH, $payload);
        $this->cache->deleteByPrefix('content_languages:');
        return $result;
    }

    private function updateContentLanguage(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $payload = $this->buildContentLanguagePayload((array) ($params['language'] ?? []), false);
        if ($id <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and language are required');
        }

        $result = $this->rest->patch(self::CONTENT_LANGUAGES_PATH . '/' . $id, $payload);
        $this->cache->delete('content_language:' . $id);
        $this->cache->deleteByPrefix('content_languages:');
        return $result;
    }

    private function deleteContentLanguage(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $result = $this->rest->delete(self::CONTENT_LANGUAGES_PATH . '/' . $id);
        $this->cache->delete('content_language:' . $id);
        $this->cache->deleteByPrefix('content_languages:');
        return $result;
    }

    private function buildContentLanguagePayload(array $input, bool $requireCore): array
    {
        $allowed = [
            'lang_code', 'title', 'title_native', 'sef', 'image', 'description',
            'metakey', 'metadesc', 'sitename', 'published', 'access', 'ordering',
        ];

        $payload = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $payload[$field] = $input[$field];
            }
        }

        if ($requireCore) {
            foreach (['lang_code', 'title', 'title_native', 'sef'] as $required) {
                if (!isset($payload[$required]) || $payload[$required] === '') {
                    throw new \InvalidArgumentException($required . ' is required');
                }
            }
            $payload['published'] = (int) ($payload['published'] ?? 1);
            $payload['access']    = (int) ($payload['access'] ?? 1);
        }

        return $payload;
    }

    private function listInstalledLanguages(array $params): array
    {
        $clientFilter = $params['client'] ?? null;
        $cacheKey = 'installed_languages:' . ($clientFilter ?? 'all');

        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($clientFilter) {
                $db = Factory::getDbo();
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['extension_id', 'name', 'element', 'client_id', 'enabled']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('language'))
                    ->order($db->quoteName('client_id') . ' ASC, ' . $db->quoteName('element') . ' ASC');

                if ($clientFilter === 'site') {
                    $query->where($db->quoteName('client_id') . ' = 0');
                } elseif ($clientFilter === 'administrator') {
                    $query->where($db->quoteName('client_id') . ' = 1');
                }

                $rows = $db->setQuery($query)->loadAssocList() ?: [];

                $data = [];
                foreach ($rows as $row) {
                    $clientId = (int) $row['client_id'];
                    $data[] = [
                        'extension_id' => (int) $row['extension_id'],
                        'name'         => (string) $row['name'],
                        'tag'          => (string) $row['element'],
                        'client_id'    => $clientId,
                        'client'       => $clientId === 0 ? 'site' : 'administrator',
                        'enabled'      => (int) $row['enabled'] === 1,
                    ];
                }

                return [
                    'data' => $data,
                    'meta' => [
                        'application_default' => (string) Factory::getApplication()->get('language', 'en-GB'),
                    ],
                ];
            }),
            $params
        );
    }

    private function listTemplateStyles(array $params): array
    {
        $client = $params['client'] ?? 'site';
        $path = $this->templateStylesPath($client);

        $query = $this->buildPageQuery($params);

        $cacheKey = 'template_styles_list:' . $client . ':' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, fn () => $this->rest->get($path, $query)),
            $params,
            'data',
            true
        );
    }

    private function getTemplateStyle(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $cacheKey = 'template_style:' . $client . ':' . $id;
        return $this->cache->remember(
            $cacheKey,
            fn () => $this->rest->get($this->templateStylesPath($client) . '/' . $id)
        );
    }

    private function createTemplateStyle(array $params): array
    {
        $template = (string) ($params['template'] ?? '');
        $title    = (string) ($params['title'] ?? '');
        if ($template === '' || $title === '') {
            throw new \InvalidArgumentException('template and title are required');
        }

        $client = $params['client'] ?? 'site';
        $payload = [
            'template'    => $template,
            'title'       => $title,
            'client_id'   => $client === 'administrator' ? 1 : 0,
            'home'        => (string) ($params['home'] ?? '0'),
            'inheritable' => (int) ($params['inheritable'] ?? 0),
            'parent'      => (string) ($params['parent'] ?? ''),
        ];

        if (isset($params['params'])) {
            $payload['params'] = (object) $params['params'];
        }

        $result = $this->rest->post($this->templateStylesPath($client), $payload);
        $this->cache->deleteByPrefix('template_styles_list:');
        return $result;
    }

    private function updateTemplateStyle(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $payload = (array) ($params['style'] ?? []);
        $client = $params['client'] ?? 'site';

        if ($id <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and style are required');
        }

        // template is read-only on update (StylesController::preprocessSaveData strips it).
        unset($payload['template'], $payload['client_id'], $payload['id']);

        if (isset($payload['params'])) {
            $payload['params'] = (object) $payload['params'];
        }

        $result = $this->rest->patch($this->templateStylesPath($client) . '/' . $id, $payload);
        $this->cache->delete('template_style:' . $client . ':' . $id);
        $this->cache->deleteByPrefix('template_styles_list:');
        return $result;
    }

    private function deleteTemplateStyle(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $params['client'] ?? 'site';

        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $result = $this->rest->delete($this->templateStylesPath($client) . '/' . $id);
        $this->cache->delete('template_style:' . $client . ':' . $id);
        $this->cache->deleteByPrefix('template_styles_list:');
        return $result;
    }

    private function templateStylesPath(string $client): string
    {
        return $client === 'administrator'
            ? 'api/index.php/v1/templates/styles/administrator'
            : 'api/index.php/v1/templates/styles/site';
    }

    private function listInstalledTemplates(array $params): array
    {
        $clientFilter = $params['client'] ?? null;
        $cacheKey = 'installed_templates:' . ($clientFilter ?? 'all');

        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($clientFilter) {
                $db = Factory::getDbo();
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['extension_id', 'name', 'element', 'client_id', 'enabled']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
                    ->order($db->quoteName('client_id') . ' ASC, ' . $db->quoteName('element') . ' ASC');

                if ($clientFilter === 'site') {
                    $query->where($db->quoteName('client_id') . ' = 0');
                } elseif ($clientFilter === 'administrator') {
                    $query->where($db->quoteName('client_id') . ' = 1');
                }

                $rows = $db->setQuery($query)->loadAssocList() ?: [];

                $data = [];
                foreach ($rows as $row) {
                    $clientId = (int) $row['client_id'];
                    $data[] = [
                        'extension_id' => (int) $row['extension_id'],
                        'name'         => (string) $row['name'],
                        'element'      => (string) $row['element'],
                        'client_id'    => $clientId,
                        'client'       => $clientId === 0 ? 'site' : 'administrator',
                        'enabled'      => (int) $row['enabled'] === 1,
                    ];
                }

                return ['data' => $data];
            }),
            $params
        );
    }

    private function listTemplateFiles(array $params): array
    {
        $template = $this->resolveTemplate((int) ($params['extension_id'] ?? 0));
        $media    = (bool) ($params['media'] ?? false);
        $base     = $this->templateBasePath($template, $media);

        if (!is_dir($base)) {
            throw new \RuntimeException('Template directory not found: ' . $template->element);
        }

        $allowed = $this->templateAllowedFormats();
        $files   = [];
        $this->scanTemplateDir($base, '', $allowed, $files);
        sort($files, SORT_NATURAL);

        return $this->withPaginationMetadata([
            'extension_id' => $template->extension_id,
            'template'     => $template->element,
            'client'       => $template->client_id === 0 ? 'site' : 'administrator',
            'media'        => $media,
            'files'        => $files,
        ], $params, 'files');
    }

    private function getTemplateFile(array $params): array
    {
        $template = $this->resolveTemplate((int) ($params['extension_id'] ?? 0));
        $media    = (bool) ($params['media'] ?? false);
        $relative = (string) ($params['path'] ?? '');

        if ($relative === '') {
            throw new \InvalidArgumentException('path is required');
        }

        $path = $this->safeTemplatePath($this->templateBasePath($template, $media), $relative);

        if (!is_file($path)) {
            throw new \InvalidArgumentException('File not found: ' . $relative);
        }

        if (!$this->templateExtensionAllowed($path)) {
            throw new \InvalidArgumentException('File type is not editable: ' . $relative);
        }

        return [
            'extension_id' => $template->extension_id,
            'template'     => $template->element,
            'path'         => $relative,
            'source'       => (string) file_get_contents($path),
        ];
    }

    private function updateTemplateFile(array $params): array
    {
        $template = $this->resolveTemplate((int) ($params['extension_id'] ?? 0));
        $media    = (bool) ($params['media'] ?? false);
        $relative = (string) ($params['path'] ?? '');

        if ($relative === '' || !array_key_exists('source', $params)) {
            throw new \InvalidArgumentException('path and source are required');
        }

        // safeTemplatePath() has already established that the parent directory
        // exists and sits inside the template, so a missing file here is a new
        // one to create — e.g. a second layout in an existing override folder.
        $path   = $this->safeTemplatePath($this->templateBasePath($template, $media), $relative);
        $isNew  = !file_exists($path);

        if (!$isNew && !is_file($path)) {
            throw new \InvalidArgumentException('Path is not a file: ' . $relative);
        }

        if (!$this->templateExtensionAllowed($path)) {
            throw new \InvalidArgumentException('File type is not editable: ' . $relative);
        }

        // Mirror Joomla's editor: normalise EOL to Unix and protect the asset manifest.
        $source = str_replace(["\r\n", "\r"], "\n", (string) $params['source']);

        if (str_ends_with($path, '/joomla.asset.json') && json_decode($source) === null) {
            throw new \InvalidArgumentException('joomla.asset.json must contain valid JSON');
        }

        $writable = $isNew ? is_writable(\dirname($path)) : is_writable($path);

        if (!$writable || file_put_contents($path, $source) === false) {
            throw new \RuntimeException('Failed to write file (check permissions): ' . $relative);
        }

        return [
            'extension_id'  => $template->extension_id,
            'template'      => $template->element,
            'path'          => $relative,
            'created'       => $isNew,
            'bytes_written' => strlen($source),
        ];
    }

    private function createTemplateOverride(array $params): array
    {
        $template = $this->resolveTemplate((int) ($params['extension_id'] ?? 0));
        $source   = trim((string) ($params['source'] ?? ''), '/');

        if ($source === '') {
            throw new \InvalidArgumentException('source is required');
        }

        $allowedRoots = [
            'components', 'modules', 'plugins', 'layouts',
            'administrator/components', 'administrator/modules',
        ];

        $matched = false;
        foreach ($allowedRoots as $root) {
            if ($source === $root || str_starts_with($source, $root . '/')) {
                $matched = true;
                break;
            }
        }

        if (!$matched || str_contains($source, '..')) {
            throw new \InvalidArgumentException('source must point inside components/, modules/, plugins/ or layouts/');
        }

        $absolute = realpath(JPATH_ROOT . '/' . $source);
        if ($absolute === false || !is_dir($absolute) || !str_starts_with($absolute, realpath(JPATH_ROOT) ?: JPATH_ROOT)) {
            throw new \InvalidArgumentException('Override source folder not found: ' . $source);
        }

        $model = $this->bootTemplateModel($template->extension_id);

        if (!$model->createOverride($absolute)) {
            throw new \RuntimeException('Joomla could not create the override for: ' . $source);
        }

        return [
            'extension_id' => $template->extension_id,
            'template'     => $template->element,
            'source'       => $source,
            'created'      => true,
        ];
    }

    /**
     * Load a template extension row (type=template) by extension_id.
     */
    private function resolveTemplate(int $extensionId): object
    {
        if ($extensionId <= 0) {
            throw new \InvalidArgumentException('extension_id is required');
        }

        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'element', 'client_id']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . $extensionId)
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'));

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            throw new \InvalidArgumentException('Template ' . $extensionId . ' not found');
        }

        $row->extension_id = (int) $row->extension_id;
        $row->client_id    = (int) $row->client_id;

        return $row;
    }

    private function templateBasePath(object $template, bool $media): string
    {
        if ($media) {
            return JPATH_ROOT . '/media/templates/'
                . ($template->client_id === 0 ? 'site' : 'administrator')
                . '/' . $template->element;
        }

        return JPATH_ROOT . '/'
            . ($template->client_id === 0 ? '' : 'administrator/')
            . 'templates/' . $template->element;
    }

    /**
     * Resolve a template-root-relative path to an absolute path, refusing any
     * traversal outside the template directory.
     */
    private function safeTemplatePath(string $base, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);

        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Invalid path');
        }

        $path     = rtrim($base, '/') . '/' . ltrim($relative, '/');
        $realBase = realpath($base) ?: $base;
        $parent   = realpath(\dirname($path));

        // '..' is rejected above, so an unresolvable parent means the directory
        // is simply absent — say so rather than implying a traversal attempt.
        if ($parent === false) {
            throw new \InvalidArgumentException(
                'Directory does not exist: ' . trim(\dirname('/' . ltrim($relative, '/')), '/')
            );
        }

        if ($parent !== $realBase && !str_starts_with($parent, $realBase . '/')) {
            throw new \InvalidArgumentException('Path is outside the template directory');
        }

        return $path;
    }

    /**
     * Recursively collect editable files as paths relative to the template root.
     */
    private function scanTemplateDir(string $base, string $prefix, array $allowed, array &$files): void
    {
        $dir = $prefix === '' ? $base : $base . '/' . $prefix;

        foreach (scandir($dir) ?: [] as $entry) {
            if (\in_array($entry, ['.', '..', 'node_modules'], true)) {
                continue;
            }

            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

            if (is_dir($dir . '/' . $entry)) {
                $this->scanTemplateDir($base, $relative, $allowed, $files);
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (\in_array($ext, $allowed, true)) {
                $files[] = $relative;
            }
        }
    }

    /**
     * Editable extensions, mirroring com_templates' allowed formats so the tool
     * exposes exactly what the Joomla "Customise" editor does.
     *
     * @return array<int,string>
     */
    private function templateAllowedFormats(): array
    {
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_templates');
        $list   = implode(',', [
            $params->get('source_formats', 'txt,less,ini,xml,js,php,css,scss,sass,json'),
            $params->get('image_formats', 'gif,bmp,jpg,jpeg,png,webp'),
            $params->get('font_formats', 'woff,woff2,ttf,otf'),
            $params->get('compressed_formats', 'zip'),
        ]);

        return array_map('strtolower', array_filter(array_map('trim', explode(',', $list))));
    }

    private function templateExtensionAllowed(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->templateAllowedFormats(), true);
    }

    /**
     * Boot the com_templates TemplateModel for override creation. The model
     * reads its template id from the request input, so we seed it there (an
     * integer survives Joomla's input filtering) and on the model state.
     */
    private function bootTemplateModel(int $extensionId): object
    {
        $app = Factory::getApplication();
        $app->getInput()->set('id', $extensionId);

        $model = $app->bootComponent('com_templates')
            ->getMVCFactory()
            ->createModel('Template', 'Administrator', ['ignore_request' => true]);

        if ($model === false) {
            throw new \RuntimeException('Unable to load the Joomla template model');
        }

        $model->setState('extension.id', $extensionId);

        return $model;
    }

    private function installExtension(array $params): array
    {
        $bytes = $this->resolveExtensionPackageBytes($params);

        $tmpPath = (string) Factory::getApplication()->get('tmp_path');
        if ($tmpPath === '' || !is_dir($tmpPath) || !is_writable($tmpPath)) {
            throw new \RuntimeException('Joomla tmp_path is not writable');
        }

        $tmpFile = rtrim($tmpPath, '/') . '/mcp-install-' . bin2hex(random_bytes(8)) . '.zip';
        if (file_put_contents($tmpFile, $bytes) === false) {
            throw new \RuntimeException('Failed to write package to tmp_path');
        }

        // Validate ZIP integrity before handing off to Joomla's installer. Joomla's archive layer
        // (libraries/vendor/joomla/archive Zip.php) walks the central directory with computed
        // offsets; a truncated or structurally corrupt package overshoots the buffer and, on PHP 8,
        // dies with a bare "Undefined array key" warning followed by a ValueError from strpos()
        // ("Argument #3 ($offset) must be contained in argument #1 ($haystack)") — which we can only
        // re-wrap as an opaque JSON-RPC -32603. A consistency check here turns that into a clear,
        // actionable message. The signature alone is not enough: the bad payloads start with a valid
        // ZIP header and only break deeper in, so use ZipArchive::CHECKCONS when ext-zip is available.
        if (class_exists(\ZipArchive::class)) {
            $za     = new \ZipArchive();
            $opened = $za->open($tmpFile, \ZipArchive::CHECKCONS);
            if ($opened === true) {
                $za->close();
            } else {
                @unlink($tmpFile);
                throw new \InvalidArgumentException(
                    'Package is not a valid ZIP archive (consistency check failed, code ' . (int) $opened . ') — '
                    . 'the download may be truncated/corrupt or the content is not a Joomla extension package'
                );
            }
        } elseif (strncmp($bytes, "PK\x03\x04", 4) !== 0 && strncmp($bytes, "PK\x05\x06", 4) !== 0) {
            @unlink($tmpFile);
            throw new \InvalidArgumentException(
                'Package is not a valid ZIP archive (missing ZIP signature) — '
                . 'the download may be truncated/corrupt or the content is not a Joomla extension package'
            );
        }

        $package = null;
        try {
            try {
                $package = \Joomla\CMS\Installer\InstallerHelper::unpack($tmpFile, true);
            } catch (\Throwable $e) {
                // The integrity check above catches the common corrupt/truncated case; anything that
                // still throws out of the core unpacker is unexpected, so preserve file:line for
                // diagnosis instead of surfacing only the bare message via JSON-RPC -32603.
                $this->logger->error('Extension unpack failed', [
                    'error' => $e->getMessage(),
                    'where' => $e->getFile() . ':' . $e->getLine(),
                ]);
                throw new \RuntimeException(
                    'Failed to unpack extension package: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            if (!is_array($package) || empty($package['type']) || empty($package['dir'])) {
                throw new \RuntimeException('Unable to detect extension type — package may be corrupt or not a Joomla extension');
            }

            $app = Factory::getApplication();
            $app->getMessageQueue(true); // flush any pre-existing messages

            $installer = \Joomla\CMS\Installer\Installer::getInstance();
            $ok = (bool) $installer->install($package['dir']);

            $messages = array_map(
                static fn ($m) => ['type' => (string) ($m['type'] ?? 'message'), 'text' => (string) ($m['message'] ?? '')],
                $app->getMessageQueue(true)
            );

            if (!$ok) {
                $reason = $messages[0]['text'] ?? 'Installer reported failure without a message';
                throw new \RuntimeException('Extension install failed: ' . $reason);
            }

            $this->invalidateExtensionCaches((string) $package['type']);

            return [
                'data' => [
                    'success'  => true,
                    'type'     => (string) $package['type'],
                    'manifest' => $installer->manifest instanceof \SimpleXMLElement
                        ? (string) ($installer->manifest->name ?? '')
                        : null,
                    'messages' => $messages,
                ],
            ];
        } finally {
            if ($package !== null) {
                \Joomla\CMS\Installer\InstallerHelper::cleanupInstall($package['packagefile'] ?? $tmpFile, $package['extractdir'] ?? ($package['dir'] ?? ''));
            } elseif (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    private function resolveExtensionPackageBytes(array $params): string
    {
        $hasContent = isset($params['content']) && $params['content'] !== '';
        $hasUrl     = isset($params['source_url']) && $params['source_url'] !== '';
        $hasPath    = isset($params['source_path']) && $params['source_path'] !== '';

        $sources = (int) $hasContent + (int) $hasUrl + (int) $hasPath;
        if ($sources > 1) {
            throw new \InvalidArgumentException('Provide exactly one of content, source_url, or source_path');
        }

        if ($hasContent) {
            $bytes = base64_decode((string) $params['content'], true);
            if ($bytes === false) {
                throw new \InvalidArgumentException('content is not valid base64');
            }
            return $bytes;
        }

        if ($hasUrl) {
            return $this->rest->fetchUrlContent((string) $params['source_url'], 120.0);
        }

        if ($hasPath) {
            return $this->readExtensionPackageFromPath((string) $params['source_path']);
        }

        throw new \InvalidArgumentException('One of content, source_url, or source_path is required');
    }

    private function readExtensionPackageFromPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('source_path is required');
        }

        if (!is_file($path)) {
            throw new \InvalidArgumentException('source_path does not exist or is not a file');
        }

        $real = realpath($path);
        if ($real === false) {
            throw new \InvalidArgumentException('source_path could not be resolved');
        }

        if (!preg_match('/\.zip$/i', $real)) {
            throw new \InvalidArgumentException('source_path must point to a .zip file');
        }

        $allowedRoots = array_values(array_filter(array_map(
            static fn (string $root): string => realpath($root) ?: '',
            array_unique([
                (string) Factory::getApplication()->get('tmp_path'),
                JPATH_ROOT,
            ])
        )));

        $allowed = false;
        foreach ($allowedRoots as $root) {
            if ($root !== '' && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new \InvalidArgumentException('source_path must be under Joomla tmp_path or the site root');
        }

        $bytes = file_get_contents($real);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read source_path');
        }

        return $bytes;
    }

    private function listExtensions(array $params): array
    {
        $filters = [];
        foreach (['type', 'client', 'enabled', 'folder', 'search'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $filters[$key] = $params[$key];
            }
        }

        $cacheKey = 'extensions_list:' . md5(json_encode($filters));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, function () use ($filters) {
                $db = Factory::getDbo();
                $query = $db->getQuery(true)
                    ->select($db->quoteName([
                        'extension_id', 'name', 'type', 'element', 'folder',
                        'client_id', 'enabled', 'protected', 'locked',
                    ]))
                    ->from($db->quoteName('#__extensions'))
                    ->order(
                        $db->quoteName('type') . ' ASC, '
                        . $db->quoteName('folder') . ' ASC, '
                        . $db->quoteName('element') . ' ASC'
                    );

                if (isset($filters['type'])) {
                    $query->where($db->quoteName('type') . ' = ' . $db->quote((string) $filters['type']));
                }
                if (isset($filters['client'])) {
                    $query->where($db->quoteName('client_id') . ' = ' . ($filters['client'] === 'administrator' ? 1 : 0));
                }
                if (isset($filters['enabled'])) {
                    $query->where($db->quoteName('enabled') . ' = ' . (int) $filters['enabled']);
                }
                if (isset($filters['folder'])) {
                    $query->where($db->quoteName('folder') . ' = ' . $db->quote((string) $filters['folder']));
                }
                if (isset($filters['search'])) {
                    $term = $db->quote('%' . $db->escape((string) $filters['search'], true) . '%');
                    $query->where(
                        '(' . $db->quoteName('name') . ' LIKE ' . $term
                        . ' OR ' . $db->quoteName('element') . ' LIKE ' . $term . ')'
                    );
                }

                $rows = $db->setQuery($query)->loadAssocList() ?: [];

                $data = [];
                foreach ($rows as $row) {
                    $clientId = (int) $row['client_id'];
                    $data[] = [
                        'extension_id' => (int) $row['extension_id'],
                        'name'         => (string) $row['name'],
                        'type'         => (string) $row['type'],
                        'element'      => (string) $row['element'],
                        'folder'       => (string) $row['folder'],
                        'client_id'    => $clientId,
                        'client'       => $clientId === 0 ? 'site' : 'administrator',
                        'enabled'      => (int) $row['enabled'] === 1,
                        'protected'    => (int) $row['protected'] === 1,
                        'locked'       => (int) $row['locked'] === 1,
                    ];
                }

                return ['data' => $data];
            }),
            $params
        );
    }

    private function setExtensionState(array $params): array
    {
        $extensionId = (int) ($params['extension_id'] ?? 0);
        if ($extensionId <= 0) {
            throw new \InvalidArgumentException('extension_id is required');
        }
        if (!isset($params['enabled'])) {
            throw new \InvalidArgumentException('enabled is required');
        }
        $enabled = (int) $params['enabled'] === 1 ? 1 : 0;

        $row = $this->loadExtensionRow($extensionId);

        // Governed mode only. GovernedToolAuthorizer maps this tool through
        // 'plugin_extension', so it can only produce an ACL decision for a
        // plugin row; anything else has no asset to authorise against and must
        // not proceed. In legacy shared-token mode there is no such mapping,
        // and the tool's own schema and list_extensions advertise every
        // extension type — restricting it there would silently break existing
        // workflows that disable a module or unpublish a template.
        if ($this->principal !== null && ($row['type'] ?? '') !== 'plugin') {
            throw new \InvalidArgumentException(
                'In governed mode only plugin extensions can have their state changed'
            );
        }

        if ($enabled === 0 && (int) $row['protected'] === 1) {
            throw new \InvalidArgumentException(
                'Extension ' . $row['name'] . ' is a protected core extension and cannot be disabled'
            );
        }

        $db = Factory::getDbo();
        $update = new \stdClass();
        $update->extension_id = $extensionId;
        $update->enabled = $enabled;
        $db->updateObject('#__extensions', $update, 'extension_id');

        $this->invalidateExtensionCaches((string) $row['type']);

        return [
            'data' => [
                'extension_id' => $extensionId,
                'name'         => (string) $row['name'],
                'type'         => (string) $row['type'],
                'element'      => (string) $row['element'],
                'enabled'      => $enabled === 1,
            ],
        ];
    }

    private function uninstallExtension(array $params): array
    {
        $extensionId = (int) ($params['extension_id'] ?? 0);
        if ($extensionId <= 0) {
            throw new \InvalidArgumentException('extension_id is required');
        }

        $row = $this->loadExtensionRow($extensionId);

        if ((int) $row['protected'] === 1 || (int) $row['locked'] === 1) {
            throw new \InvalidArgumentException(
                'Extension ' . $row['name'] . ' is a protected or locked core extension and cannot be uninstalled'
            );
        }

        $app = Factory::getApplication();
        $app->getMessageQueue(true); // flush any pre-existing messages

        $installer = \Joomla\CMS\Installer\Installer::getInstance();
        $ok = (bool) $installer->uninstall((string) $row['type'], $extensionId);

        $messages = array_map(
            static fn ($m) => ['type' => (string) ($m['type'] ?? 'message'), 'text' => (string) ($m['message'] ?? '')],
            $app->getMessageQueue(true)
        );

        if (!$ok) {
            $reason = $messages[0]['text'] ?? 'Installer reported failure without a message';
            throw new \RuntimeException('Extension uninstall failed: ' . $reason);
        }

        $this->invalidateExtensionCaches((string) $row['type']);

        return [
            'data' => [
                'success'      => true,
                'extension_id' => $extensionId,
                'name'         => (string) $row['name'],
                'type'         => (string) $row['type'],
                'messages'     => $messages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExtensionRow(int $extensionId): array
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'extension_id', 'name', 'type', 'element', 'folder',
                'client_id', 'enabled', 'protected', 'locked',
            ]))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . $extensionId);
        $row = $db->setQuery($query)->loadAssoc();

        if (empty($row)) {
            throw new \InvalidArgumentException('Extension ' . $extensionId . ' not found');
        }

        return $row;
    }

    private function invalidateExtensionCaches(string $type): void
    {
        $this->cache->deleteByPrefix('extensions_list:');
        $this->cache->deleteByPrefix('installed_templates:');
        $this->cache->deleteByPrefix('installed_languages:');

        // Joomla caches the enabled-plugin list in its own com_plugins cache
        // group; clear it so a toggled plugin takes effect without waiting for
        // the cache to expire.
        if ($type === 'plugin') {
            (new JoomlaCache('com_plugins'))->clear();
        }
    }

    private function clearJoomlaCache(array $params): array
    {
        $group = trim((string) ($params['group'] ?? ''));
        $client = (string) ($params['client'] ?? 'both');

        $clientIds = match ($client) {
            'site'          => [0],
            'administrator' => [1],
            default         => [0, 1],
        };

        $app = Factory::getApplication();
        $cleared = [];

        foreach ($clientIds as $clientId) {
            // Each Joomla client has its own cache base, and this endpoint can
            // run in either application, so set cachebase explicitly per client
            // (as com_cache's CacheModel does) instead of relying on the
            // current application's JPATH_CACHE.
            $cache = new Cache([
                'defaultgroup' => '',
                'storage'      => $app->get('cache_handler', 'file'),
                'caching'      => true,
                'cachebase'    => $clientId === 1
                    ? JPATH_ADMINISTRATOR . '/cache'
                    : $app->get('cache_path', JPATH_SITE . '/cache'),
            ]);

            if ($group !== '') {
                $groups = [$group];
            } else {
                $groups = [];
                $items = $cache->getAll();
                foreach (is_array($items) ? $items : [] as $key => $item) {
                    $itemGroup = (string) ($item->group ?? $key);
                    if ($itemGroup !== '') {
                        $groups[] = $itemGroup;
                    }
                }
            }

            $groups = array_values(array_filter(
                $groups,
                static fn (string $itemGroup): bool => !in_array($itemGroup, self::PROTECTED_CACHE_GROUPS, true)
            ));

            foreach ($groups as $itemGroup) {
                $cache->clean($itemGroup);
            }

            $cleared[$clientId === 1 ? 'administrator' : 'site'] = $groups;
        }

        // Let cache/purge plugins (CDN, reverse proxy, ...) react, as
        // com_cache does after a clean. AfterPurgeEvent exists from Joomla 5
        // and carries the group under the legacy argument name 'subject';
        // triggerEvent() is the Joomla 4 equivalent (removed in Joomla 6).
        // The cache is already cleared at this point, so a plugin/event
        // failure must not fail the tool call.
        if (!in_array($group, self::PROTECTED_CACHE_GROUPS, true)) {
            try {
                $eventArguments = $group !== '' ? ['subject' => $group] : [];
                if (class_exists(AfterPurgeEvent::class)) {
                    $app->getDispatcher()->dispatch('onAfterPurge', new AfterPurgeEvent('onAfterPurge', $eventArguments));
                } elseif (method_exists($app, 'triggerEvent')) {
                    $app->triggerEvent('onAfterPurge', $group !== '' ? [$group] : []);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('onAfterPurge dispatch failed after cache clear', ['error' => $e->getMessage()]);
            }
        }

        return [
            'data' => [
                'success' => true,
                'cleared' => $cleared,
            ],
        ];
    }

    private function getRenderedPage(array $params): array
    {
        $articleId = (int) ($params['article_id'] ?? 0);
        $menuItemId = (int) ($params['menu_item_id'] ?? 0);
        $hasArticle = $articleId > 0;
        $hasMenu = $menuItemId > 0;

        if ($hasArticle === $hasMenu) {
            throw new \InvalidArgumentException('Provide exactly one of article_id or menu_item_id');
        }

        if ($hasArticle) {
            $catid = 0;
            try {
                $article = $this->getArticleById(['id' => $articleId]);
                $catid = (int) ($article['data']['attributes']['catid'] ?? 0);
            } catch (RequestException $e) {
                if ($e->getResponse()?->getStatusCode() !== 404) {
                    throw $e;
                }
            }
            $path = 'index.php?option=com_content&view=article&id=' . $articleId . '&catid=' . $catid;
        } else {
            $path = 'index.php?Itemid=' . $menuItemId;
        }

        try {
            $fetched = $this->rest->fetchRenderedPage($path);
        } catch (ConnectException $e) {
            throw new \RuntimeException(
                'Could not fetch the rendered page. Check the Base URL and Resolve Host To IP settings in MCP Server options.',
                0,
                $e
            );
        }

        $format = ($params['format'] ?? 'html') === 'text' ? 'text' : 'html';
        $content = $format === 'text' ? $this->htmlToText((string) $fetched['body']) : (string) $fetched['body'];

        return [
            'data' => [
                'requested_path' => $path,
                'final_url' => (string) $fetched['final_url'],
                'status' => (int) $fetched['status'],
                'format' => $format,
                'content' => $content,
                'truncated' => (bool) $fetched['truncated'],
                'content_bytes' => strlen($content),
            ],
        ];
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace(
            '#</?(p|div|h[1-6]|li|tr|blockquote|pre|hr|ul|ol|table|section|article|header|footer|nav)(?:\s[^>]*)?>#i',
            "\n",
            $text
        ) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function seoAuditArticles(array $params): array
    {
        $articles = $this->cache->remember('articles_search:seo_audit', fn () => $this->fetchAllPages(
            'api/index.php/v1/content/articles',
            ['filter[state]' => 1]
        ));

        $issues = $this->analyseArticleSeo($articles);
        $issueCounts = [];
        foreach ($issues as $article) {
            foreach ($article['issues'] as $issue) {
                $code = (string) $issue['code'];
                $issueCounts[$code] = ($issueCounts[$code] ?? 0) + 1;
            }
        }

        $summary = [
            'articles_checked' => count($articles),
            'articles_with_issues' => count($issues),
            'issue_counts' => $issueCounts,
        ];
        if (count($articles) >= self::FETCH_ALL_PAGES_CAP) {
            $summary['articles_limit_reached'] = true;
        }

        return $this->withPaginationMetadata([
            'data' => $issues,
            'summary' => $summary,
        ], $params);
    }

    /**
     * @param  list<array<string, mixed>>  $articles
     * @return list<array<string, mixed>>
     */
    private function analyseArticleSeo(array $articles): array
    {
        $aliasBuckets = [];
        $rows = [];

        foreach ($articles as $article) {
            $id = (int) ($article['id'] ?? 0);
            $attrs = is_array($article['attributes'] ?? null) ? $article['attributes'] : [];
            $title = trim((string) ($attrs['title'] ?? ''));
            $alias = strtolower(trim((string) ($attrs['alias'] ?? '')));
            $catid = (int) ($attrs['catid'] ?? 0);
            $metadesc = trim((string) ($attrs['metadesc'] ?? ''));

            $issues = [];
            if ($title === '') {
                $issues[] = ['code' => 'missing_title'];
            }
            if ($metadesc === '') {
                $issues[] = ['code' => 'missing_metadesc'];
            } else {
                $length = mb_strlen($metadesc);
                if ($length < self::SEO_METADESC_MIN) {
                    $issues[] = ['code' => 'short_metadesc'];
                } elseif ($length > self::SEO_METADESC_MAX) {
                    $issues[] = ['code' => 'long_metadesc', 'metadesc_length' => $length];
                }
            }

            if ($alias !== '') {
                $aliasBuckets[$catid . ':' . $alias][] = $id;
            }

            $rows[] = [
                'id' => $id,
                'title' => $title,
                'alias' => (string) ($attrs['alias'] ?? ''),
                'catid' => $catid,
                'issues' => $issues,
            ];
        }

        $siblingsById = [];
        foreach ($aliasBuckets as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $siblingsById[$id] = array_values(array_filter($ids, static fn (int $other): bool => $other !== $id));
            }
        }

        $withIssues = [];
        foreach ($rows as $row) {
            if (isset($siblingsById[$row['id']])) {
                $row['issues'][] = [
                    'code' => 'duplicate_alias',
                    'sibling_ids' => $siblingsById[$row['id']],
                ];
            }
            if ($row['issues'] !== []) {
                $withIssues[] = $row;
            }
        }

        return $withIssues;
    }

    private function checkInternalLinks(array $params): array
    {
        $published = $this->cache->remember('articles_search:link_audit:published', fn () => $this->fetchAllPages(
            'api/index.php/v1/content/articles',
            ['filter[state]' => 1]
        ));
        $unpublished = $this->cache->remember('articles_search:link_audit:unpublished', fn () => $this->fetchAllPages(
            'api/index.php/v1/content/articles',
            ['filter[state]' => 0]
        ));
        $menuItems = $this->cache->remember('menu_items:site:link_audit', fn () => $this->fetchAllPages(
            'api/index.php/v1/menus/site/items'
        ));

        $states = [];
        $aliases = [];
        foreach ($unpublished as $article) {
            $this->indexArticleForLinkAudit($article, 0, $states, $aliases);
        }
        foreach ($published as $article) {
            $this->indexArticleForLinkAudit($article, 1, $states, $aliases);
        }

        $menuPaths = [];
        foreach ($menuItems as $item) {
            $attrs = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $path = trim((string) ($attrs['path'] ?? ''), '/');
            if ($path === '') {
                $path = trim((string) ($attrs['alias'] ?? ''), '/');
            }
            if ($path !== '') {
                $menuPaths[] = strtolower($path);
            }
        }

        $articleId = (int) ($params['article_id'] ?? 0);
        if ($articleId > 0) {
            $article = $this->getArticleById(['id' => $articleId]);
            $source = [[
                'id' => $articleId,
                'attributes' => $article['data']['attributes'] ?? [],
            ]];
        } else {
            $source = $published;
        }

        $baseUrl = $this->rest->getBaseUrl();
        $basePath = (string) (parse_url($baseUrl, \PHP_URL_PATH) ?? '');

        $perArticle = [];
        $summary = [
            'articles_checked' => 0,
            'links_total' => 0,
            'internal' => 0,
            'external' => 0,
            'ok' => 0,
            'missing' => 0,
            'unpublished' => 0,
            'unknown' => 0,
        ];

        foreach ($source as $article) {
            $id = (int) ($article['id'] ?? 0);
            $attrs = is_array($article['attributes'] ?? null) ? $article['attributes'] : [];
            $html = (string) ($attrs['introtext'] ?? '') . "\n" . (string) ($attrs['fulltext'] ?? '');
            $links = [];

            foreach (LinkAudit::extractLinks($html) as $url) {
                $summary['links_total']++;
                $kind = LinkAudit::classify($url, $baseUrl);
                if ($kind === 'skip') {
                    continue;
                }
                if ($kind === 'external') {
                    $summary['external']++;
                    $links[] = ['url' => $url, 'status' => 'not_checked'];
                    continue;
                }

                $summary['internal']++;
                $resolved = LinkAudit::resolve($url, $basePath, $states, $aliases, $menuPaths);
                $summary[$resolved['status']] = ($summary[$resolved['status']] ?? 0) + 1;
                $entry = ['url' => $url, 'status' => $resolved['status']];
                if ($resolved['target_article_id'] !== null) {
                    $entry['target_article_id'] = $resolved['target_article_id'];
                }
                $links[] = $entry;
            }

            $summary['articles_checked']++;
            $perArticle[] = [
                'id' => $id,
                'title' => (string) ($attrs['title'] ?? ''),
                'links' => $links,
            ];
        }

        return $this->withPaginationMetadata([
            'data' => $perArticle,
            'summary' => $summary,
        ], $params);
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  array<int, int>  $states
     * @param  array<string, int>  $aliases
     */
    private function indexArticleForLinkAudit(array $article, int $state, array &$states, array &$aliases): void
    {
        $id = (int) ($article['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $attrs = is_array($article['attributes'] ?? null) ? $article['attributes'] : [];
        $states[$id] = (int) ($attrs['state'] ?? $state);
        $alias = strtolower(trim((string) ($attrs['alias'] ?? '')));
        if ($alias !== '') {
            $aliases[$alias] = $id;
        }
    }

    private function listArticleAssociations(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }
        return $this->withPaginationMetadata($this->loadAssociations(self::ASSOC_CONTEXT_ARTICLE, $id), $params);
    }

    private function setArticleAssociations(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }
        if (!array_key_exists('associated_ids', $params) || !is_array($params['associated_ids'])) {
            throw new \InvalidArgumentException('associated_ids is required');
        }
        $associatedIds = array_map('intval', $params['associated_ids']);

        return $this->saveAssociations(self::ASSOC_CONTEXT_ARTICLE, $id, $associatedIds);
    }

    private function listMenuItemAssociations(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }
        return $this->withPaginationMetadata($this->loadAssociations(self::ASSOC_CONTEXT_MENU_ITEM, $id), $params);
    }

    private function setMenuItemAssociations(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }
        if (!array_key_exists('associated_ids', $params) || !is_array($params['associated_ids'])) {
            throw new \InvalidArgumentException('associated_ids is required');
        }
        $associatedIds = array_map('intval', $params['associated_ids']);

        return $this->saveAssociations(self::ASSOC_CONTEXT_MENU_ITEM, $id, $associatedIds);
    }

    private function loadAssociations(string $context, int $primaryId): array
    {
        $cacheKey = $this->associationCacheKey($context, $primaryId);
        return $this->cache->remember($cacheKey, function () use ($context, $primaryId) {
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select($db->quoteName('key'))
                ->from($db->quoteName('#__associations'))
                ->where($db->quoteName('context') . ' = ' . $db->quote($context))
                ->where($db->quoteName('id') . ' = ' . (int) $primaryId);
            $key = $db->setQuery($query)->loadResult();

            if (!$key) {
                return [
                    'data' => [],
                    'meta' => ['key' => null, 'context' => $context, 'id' => $primaryId],
                ];
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__associations'))
                ->where($db->quoteName('context') . ' = ' . $db->quote($context))
                ->where($db->quoteName('key') . ' = ' . $db->quote($key));
            $allIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

            $items = $this->loadItemsForAssociation($context, $allIds);

            return [
                'data' => $items,
                'meta' => ['key' => $key, 'context' => $context, 'id' => $primaryId],
            ];
        });
    }

    private function saveAssociations(string $context, int $primaryId, array $associatedIds): array
    {
        $allIds = array_values(array_unique(array_map('intval', array_merge([$primaryId], $associatedIds))));

        if (count($allIds) === 1) {
            // Caller wants to clear associations; just remove rows for the primary.
            $this->deleteAssociationsForIds($context, $allIds);
            $this->cache->deleteByPrefix($this->associationCachePrefix($context));
            return $this->loadAssociations($context, $primaryId);
        }

        $items = $this->loadItemsForAssociation($context, $allIds);
        $byId = [];
        foreach ($items as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $assocMap = [];
        foreach ($allIds as $itemId) {
            if (!isset($byId[$itemId])) {
                throw new \InvalidArgumentException("Item with id {$itemId} not found for context '{$context}'");
            }
            $lang = (string) ($byId[$itemId]['language'] ?? '');
            if ($lang === '' || $lang === '*') {
                throw new \InvalidArgumentException(
                    "Item id {$itemId} has language '" . ($lang === '' ? '' : $lang)
                    . "'; associations require a specific language tag"
                );
            }
            if (isset($assocMap[$lang])) {
                throw new \InvalidArgumentException(
                    "Language conflict: items {$assocMap[$lang]} and {$itemId} both have language '{$lang}'."
                    . ' Associations require one item per language.'
                );
            }
            $assocMap[$lang] = $itemId;
        }

        $db = Factory::getDbo();
        $this->deleteAssociationsForIds($context, $allIds);

        $key = md5(json_encode($assocMap));
        foreach ($assocMap as $itemId) {
            $row = new \stdClass();
            $row->id      = (int) $itemId;
            $row->context = $context;
            $row->key     = $key;
            $db->insertObject('#__associations', $row);
        }

        $this->cache->deleteByPrefix($this->associationCachePrefix($context));
        return $this->loadAssociations($context, $primaryId);
    }

    private function loadItemsForAssociation(string $context, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $db = Factory::getDbo();

        if ($context === self::ASSOC_CONTEXT_ARTICLE) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title', 'alias', 'language', 'catid', 'state']))
                ->from($db->quoteName('#__content'))
                ->whereIn($db->quoteName('id'), $ids);
        } elseif ($context === self::ASSOC_CONTEXT_MENU_ITEM) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title', 'alias', 'language', 'menutype', 'client_id', 'published']))
                ->from($db->quoteName('#__menu'))
                ->whereIn($db->quoteName('id'), $ids);
        } else {
            throw new \InvalidArgumentException("Unsupported association context '{$context}'");
        }

        $rows = $db->setQuery($query)->loadAssocList() ?: [];

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            if (isset($row['client_id'])) {
                $row['client_id'] = (int) $row['client_id'];
            }
            return $row;
        }, $rows);
    }

    private function deleteAssociationsForIds(string $context, array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = ' . $db->quote($context))
            ->whereIn($db->quoteName('id'), $ids);
        $db->setQuery($query)->execute();
    }

    private function associationCacheKey(string $context, int $id): string
    {
        return $this->associationCachePrefix($context) . $id;
    }

    private function associationCachePrefix(string $context): string
    {
        if ($context === self::ASSOC_CONTEXT_ARTICLE) {
            return 'article_associations:';
        }
        if ($context === self::ASSOC_CONTEXT_MENU_ITEM) {
            return 'menu_item_associations:';
        }
        return 'associations:' . $context . ':';
    }

    private function listCategories(array $params): array
    {
        $query = $this->buildPageQuery($params);

        $cacheKey = 'categories_list:' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, fn () => $this->rest->get(self::CATEGORIES_PATH, $query)),
            $params,
            'data',
            true
        );
    }

    private function getCategory(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $cacheKey = 'category:' . $id;
        return $this->cache->remember($cacheKey, fn () => $this->rest->get(self::CATEGORIES_PATH . '/' . $id));
    }

    private function createCategory(array $params): array
    {
        $title = (string) ($params['title'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }

        $payload = [
            'title'     => $title,
            'parent_id' => (int) ($params['parent_id'] ?? 1),
            'published' => (int) ($params['published'] ?? 1),
            'access'    => (int) ($params['access'] ?? 1),
            'language'  => $params['language'] ?? '*',
        ];

        foreach (['alias', 'description', 'note'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = $params[$key];
            }
        }

        $result = $this->rest->post(self::CATEGORIES_PATH, $payload);
        $this->cache->deleteByPrefix('categories_list:');
        return $result;
    }

    private function updateCategory(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $payload = (array) ($params['category'] ?? []);
        if ($id <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and category are required');
        }

        $result = $this->rest->patch(self::CATEGORIES_PATH . '/' . $id, $payload);
        $this->cache->delete('category:' . $id);
        $this->cache->deleteByPrefix('categories_list:');
        return $result;
    }

    private function deleteCategory(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        // Joomla only deletes trashed categories (CategoryModel::canDelete requires
        // published -2), so trash first when needed. The current title rides along
        // because the category form validates it as a required field.
        $current = $this->rest->get(self::CATEGORIES_PATH . '/' . $id);
        $attributes = $current['data']['attributes'] ?? [];
        if ((int) ($attributes['published'] ?? 0) !== -2) {
            $this->rest->patch(self::CATEGORIES_PATH . '/' . $id, [
                'title'     => (string) ($attributes['title'] ?? ''),
                'published' => -2,
            ]);
        }

        $result = $this->rest->delete(self::CATEGORIES_PATH . '/' . $id);
        $this->cache->delete('category:' . $id);
        $this->cache->deleteByPrefix('categories_list:');
        $this->cache->deleteByPrefix('articles_search:');
        return $result;
    }

    private function listTags(array $params): array
    {
        $query = $this->buildPageQuery($params);

        $cacheKey = 'tags_list:' . md5(json_encode($query));
        return $this->withPaginationMetadata(
            $this->cache->remember($cacheKey, fn () => $this->rest->get(self::TAGS_PATH, $query)),
            $params,
            'data',
            true
        );
    }

    private function getTag(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $cacheKey = 'tag:' . $id;
        return $this->cache->remember($cacheKey, fn () => $this->rest->get(self::TAGS_PATH . '/' . $id));
    }

    private function createTag(array $params): array
    {
        $title = (string) ($params['title'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }

        $payload = [
            'title'     => $title,
            'parent_id' => (int) ($params['parent_id'] ?? 1),
            'published' => (int) ($params['published'] ?? 1),
            'access'    => (int) ($params['access'] ?? 1),
            'language'  => $params['language'] ?? '*',
        ];

        foreach (['alias', 'description', 'note'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = $params[$key];
            }
        }

        $result = $this->rest->post(self::TAGS_PATH, $payload);
        $this->cache->deleteByPrefix('tags_list:');
        return $result;
    }

    private function updateTag(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $payload = (array) ($params['tag'] ?? []);
        if ($id <= 0 || empty($payload)) {
            throw new \InvalidArgumentException('id and tag are required');
        }

        $result = $this->rest->patch(self::TAGS_PATH . '/' . $id, $payload);
        $this->cache->delete('tag:' . $id);
        $this->cache->deleteByPrefix('tags_list:');
        return $result;
    }

    private function deleteTag(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        // Trash first, mirroring the other delete tools: deleting a trashed item
        // is accepted everywhere, so this works whether or not TagModel enforces
        // the trashed-state precondition.
        $current = $this->rest->get(self::TAGS_PATH . '/' . $id);
        $attributes = $current['data']['attributes'] ?? [];
        if ((int) ($attributes['published'] ?? 0) !== -2) {
            $this->rest->patch(self::TAGS_PATH . '/' . $id, [
                'title'     => (string) ($attributes['title'] ?? ''),
                'published' => -2,
            ]);
        }

        $result = $this->rest->delete(self::TAGS_PATH . '/' . $id);
        $this->cache->delete('tag:' . $id);
        $this->cache->deleteByPrefix('tags_list:');
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatToolSuccess(mixed $result): array
    {
        $response = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];

        if (is_array($result)) {
            $response['structuredContent'] = $result;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatToolError(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            'isError' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildPageQuery(array $params): array
    {
        $query = [];
        if (isset($params['limit'])) {
            $query['page[limit]'] = max(1, (int) $params['limit']);
        }
        if (isset($params['offset'])) {
            $query['page[offset]'] = max(0, (int) $params['offset']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withPaginationMetadata(
        array $response,
        array $params = [],
        string $itemsKey = 'data',
        bool $apiHandlesPaging = false
    ): array {
        if (!isset($response[$itemsKey]) || !is_array($response[$itemsKey])) {
            return $response;
        }

        $items = $response[$itemsKey];
        if ($this->isAssociativeRecord($items)) {
            return $response;
        }

        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : null;
        $apiPaginated = $this->responseIsApiPaginated($response, $params, $apiHandlesPaging);
        $total = $this->inferTotalCount($response, $items, $params);

        if ($limit !== null && !$apiPaginated) {
            $response[$itemsKey] = array_values(array_slice($items, $offset, $limit));
        }

        $count = count($response[$itemsKey]);
        $effectiveOffset = $apiPaginated ? $this->inferApiOffset($response, $offset) : $offset;
        $nextOffset = $effectiveOffset + $count;
        $hasMore = $this->inferHasMore($response, $effectiveOffset, $count, $total, $limit);

        $response['pagination'] = [
            'total_count' => $total,
            'count' => $count,
            'offset' => $effectiveOffset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $nextOffset : null,
        ];

        if ($limit !== null) {
            $response['pagination']['limit'] = $limit;
        }

        return $response;
    }

    /**
     * @param  array<mixed>  $items
     */
    private function isAssociativeRecord(array $items): bool
    {
        return isset($items['id']) || isset($items['type']);
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<string, mixed>  $params
     */
    private function inferTotalCount(array $response, array $items, array $params = []): int
    {
        $meta = $response['meta'] ?? [];
        if (!is_array($meta)) {
            return count($items);
        }

        foreach (['total-items', 'total_items', 'total'] as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                return (int) $meta[$key];
            }
        }

        $totalPages = (int) ($meta['total-pages'] ?? $meta['total_pages'] ?? 0);
        if ($totalPages <= 1) {
            return count($items);
        }

        $pageOffset = (int) ($meta['page-offset'] ?? $meta['page_offset'] ?? ($params['offset'] ?? 0));
        $requestedLimit = isset($params['limit']) ? (int) $params['limit'] : null;
        $pageLimit = $this->inferPageLimit($response, count($items), $pageOffset, $requestedLimit);
        $itemCount = count($items);

        if ($itemCount > 0 && $itemCount < $pageLimit) {
            return $pageOffset + $itemCount;
        }

        return $totalPages * $pageLimit;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $params
     */
    private function responseIsApiPaginated(array $response, array $params = [], bool $apiHandlesPaging = false): bool
    {
        if ($apiHandlesPaging && (isset($params['limit']) || isset($params['offset']))) {
            return true;
        }

        $meta = $response['meta'] ?? [];
        if (!is_array($meta)) {
            return false;
        }

        $totalPages = (int) ($meta['total-pages'] ?? $meta['total_pages'] ?? 0);
        if ($totalPages > 1) {
            return true;
        }

        return isset($meta['page-limit'])
            || isset($meta['page_limit'])
            || isset($meta['page-offset'])
            || isset($meta['page_offset']);
    }

    private function inferPageLimit(array $response, int $count, int $offset, ?int $requestedLimit): int
    {
        $meta = $response['meta'] ?? [];
        if (is_array($meta)) {
            $pageLimit = (int) ($meta['page-limit'] ?? $meta['page_limit'] ?? 0);
            if ($pageLimit > 0) {
                return $pageLimit;
            }
        }

        if ($requestedLimit !== null && $requestedLimit > 0) {
            return $requestedLimit;
        }

        return max($count, 1);
    }

    private function inferHasMore(array $response, int $offset, int $count, int $total, ?int $requestedLimit): bool
    {
        if ($count === 0) {
            return false;
        }

        $meta = $response['meta'] ?? [];
        if (is_array($meta)) {
            $totalPages = (int) ($meta['total-pages'] ?? $meta['total_pages'] ?? 0);
            if ($totalPages > 1) {
                $pageLimit = $this->inferPageLimit($response, $count, $offset, $requestedLimit);
                $pageOffset = (int) ($meta['page-offset'] ?? $meta['page_offset'] ?? $offset);
                $currentPage = (int) floor($pageOffset / max($pageLimit, 1)) + 1;

                return $currentPage < $totalPages;
            }
        }

        return ($offset + $count) < $total;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function inferApiOffset(array $response, int $fallback): int
    {
        $meta = $response['meta'] ?? [];

        if (isset($meta['page-offset'])) {
            return (int) $meta['page-offset'];
        }

        return $fallback;
    }

    // ---------------------------------------------------------------------
    // Custom fields (com_fields)
    // ---------------------------------------------------------------------

    private function listFieldGroups(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $query = $this->fieldListQuery($params, ['state']);
        $path = $this->fieldGroupsPath($context);
        $cacheKey = 'field_groups_list:' . $context . ':' . md5(json_encode($query));

        return $this->withPaginationMetadata(
            $this->cache->remember(
                $cacheKey,
                fn () => $this->withFieldRouteErrors($context, fn () => $this->rest->get($path, $query))
            ),
            $params,
            'data',
            true
        );
    }

    private function getFieldGroup(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);

        return $this->fetchFieldResponse($this->fieldGroupsPath($context), 'Field group', $context, $id);
    }

    private function createFieldGroup(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $path = $this->fieldGroupsPath($context);

        $payload = [
            'context'  => $context,
            'title'    => $this->requireFieldString($params, 'title'),
            'state'    => (int) ($params['state'] ?? 1),
            'access'   => (int) ($params['access'] ?? 1),
            'language' => (string) ($params['language'] ?? '*'),
        ];

        foreach (['description', 'note'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = (string) $params[$key];
            }
        }

        if (is_array($params['params'] ?? null)) {
            $payload['params'] = $params['params'];
        }

        $result = $this->withFieldRouteErrors($context, fn () => $this->rest->post($path, $payload));
        $this->invalidateFieldCaches();

        return $result;
    }

    private function updateFieldGroup(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);
        $path = $this->fieldGroupsPath($context);

        $this->requireFieldUpdates(
            $params,
            array_merge(self::FIELD_GROUP_STRING_COLUMNS, self::FIELD_GROUP_INT_COLUMNS, ['params'])
        );

        $existing = $this->fieldAttributes(
            $this->fetchFieldResponse($path, 'Field group', $context, $id),
            'field group',
            $id
        );

        $payload = $this->buildFieldPayload($params, self::FIELD_GROUP_STRING_COLUMNS, self::FIELD_GROUP_INT_COLUMNS);
        $payload['context'] = $context;
        $payload['params'] = $this->mergeFieldRegistry(
            $existing['params'] ?? null,
            is_array($params['params'] ?? null) ? $params['params'] : []
        );

        $this->rest->patch($path . '/' . $id, $payload);
        $this->invalidateFieldCaches();

        return $this->rest->get($path . '/' . $id);
    }

    private function deleteFieldGroup(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);

        return $this->deleteFieldRecord($this->fieldGroupsPath($context), 'Field group', $context, $id);
    }

    private function reorderFieldGroups(array $params): array
    {
        $context = $this->requireFieldContext($params);

        return $this->reorderFieldRecords($params, $this->fieldGroupsPath($context), 'Field group', $context);
    }

    private function listFields(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $query = $this->fieldListQuery($params, ['state', 'group_id'], ['search']);
        $path = $this->fieldsPath($context);
        $cacheKey = 'fields_list:' . $context . ':' . md5(json_encode($query));

        return $this->withPaginationMetadata(
            $this->cache->remember(
                $cacheKey,
                fn () => $this->withFieldRouteErrors($context, fn () => $this->rest->get($path, $query))
            ),
            $params,
            'data',
            true
        );
    }

    private function getField(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);

        $response = $this->fetchFieldResponse($this->fieldsPath($context), 'Field', $context, $id);

        if (is_array($response['data']['attributes'] ?? null)) {
            $response['data']['attributes'] = $this->decorateFieldRecord($response['data']['attributes']);
        }

        return $response;
    }

    private function findFieldByName(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $name = $this->requireFieldString($params, 'name');

        foreach ($this->fetchAllFieldRecords($this->fieldsPath($context), $context) as $record) {
            $attributes = $record['attributes'] ?? null;

            if (!is_array($attributes) || strcasecmp((string) ($attributes['name'] ?? ''), $name) !== 0) {
                continue;
            }

            $record['attributes'] = $this->decorateFieldRecord($attributes);

            return ['data' => $record];
        }

        throw new \InvalidArgumentException(
            'No field with the technical name "' . $name . '" exists in context ' . $context
            . '. Use list_fields to see the available names.'
        );
    }

    private function createField(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $path = $this->fieldsPath($context);

        $payload = $this->buildFieldPayload($params, self::FIELD_STRING_COLUMNS, self::FIELD_INT_COLUMNS);
        $payload['context'] = $context;
        $payload['title'] = $this->requireFieldString($params, 'title');
        $payload['type'] = (string) ($params['type'] ?? 'text');
        $payload['state'] = (int) ($params['state'] ?? 1);
        $payload['access'] = (int) ($params['access'] ?? 1);
        $payload['required'] = (int) ($params['required'] ?? 0);
        $payload['language'] = (string) ($params['language'] ?? '*');

        foreach (['params', 'fieldparams'] as $key) {
            if (is_array($params[$key] ?? null)) {
                $payload[$key] = $params[$key];
            }
        }

        if (array_key_exists('assigned_cat_ids', $params)) {
            $payload['assigned_cat_ids'] = $this->normaliseAssignedCatIds($params['assigned_cat_ids']);
        }

        $result = $this->withFieldRouteErrors($context, fn () => $this->rest->post($path, $payload));
        $this->invalidateFieldCaches();
        $this->invalidateFieldValueItemCaches($context);

        return $result;
    }

    private function updateField(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);
        $path = $this->fieldsPath($context);

        $this->requireFieldUpdates(
            $params,
            array_merge(
                self::FIELD_STRING_COLUMNS,
                self::FIELD_INT_COLUMNS,
                ['params', 'fieldparams', 'assigned_cat_ids']
            )
        );

        $existing = $this->fieldAttributes(
            $this->fetchFieldResponse($path, 'Field', $context, $id),
            'field',
            $id
        );

        $payload = $this->buildFieldPayload($params, self::FIELD_STRING_COLUMNS, self::FIELD_INT_COLUMNS);
        $payload['context'] = $context;

        // Joomla's PATCH handler backfills table columns only, and FieldTable::bind then replaces
        // the whole params/fieldparams column with whatever array it is handed. A partial object
        // would silently discard every key the caller did not send, so merge and send them whole.
        $payload['params'] = $this->mergeFieldRegistry(
            $existing['params'] ?? null,
            is_array($params['params'] ?? null) ? $params['params'] : []
        );
        $payload['fieldparams'] = $this->mergeFieldRegistry(
            $existing['fieldparams'] ?? null,
            is_array($params['fieldparams'] ?? null) ? $params['fieldparams'] : []
        );

        // assigned_cat_ids is not a column — it lives in #__fields_categories — so the PATCH
        // backfill never restores it, and FieldModel::save() deletes every assignment when it is
        // absent. Always resend it, keeping Joomla's [0] "all categories" sentinel as it came.
        $payload['assigned_cat_ids'] = array_key_exists('assigned_cat_ids', $params)
            ? $this->normaliseAssignedCatIds($params['assigned_cat_ids'])
            : $this->normaliseAssignedCatIds($existing['assigned_cat_ids'] ?? []);

        // 'rules' is deliberately never sent: FieldTable::bind only calls setRules() when the key
        // is present, so omitting it leaves the field's permissions untouched.
        $this->rest->patch($path . '/' . $id, $payload);
        $this->invalidateFieldCaches();
        $this->invalidateFieldValueItemCaches($context);

        $response = $this->rest->get($path . '/' . $id);

        if (is_array($response['data']['attributes'] ?? null)) {
            $response['data']['attributes'] = $this->decorateFieldRecord($response['data']['attributes']);
        }

        return $response;
    }

    private function deleteField(array $params): array
    {
        $context = $this->requireFieldContext($params);
        $id = $this->requireFieldId($params);

        $result = $this->deleteFieldRecord($this->fieldsPath($context), 'Field', $context, $id);
        $this->invalidateFieldValueItemCaches($context);

        return $result;
    }

    private function reorderFields(array $params): array
    {
        $context = $this->requireFieldContext($params);

        $result = $this->reorderFieldRecords($params, $this->fieldsPath($context), 'Field', $context);
        // Values do not change, but items render their fields in this order.
        $this->invalidateFieldValueItemCaches($context);

        return $result;
    }

    private function getItemFieldValues(array $params): array
    {
        $context = $this->requireFieldValueContext($params);
        $itemId = $this->requireFieldId($params, 'item_id');
        $attributes = $this->fetchFieldValueItem($context, $itemId);

        $values = [];

        foreach ($this->fetchAllFieldRecords($this->fieldsPath($context), $context) as $record) {
            $field = $record['attributes'] ?? null;
            $name = is_array($field) ? (string) ($field['name'] ?? '') : '';

            if ($name === '' || !array_key_exists($name, $attributes)) {
                continue;
            }

            $options = $this->expandFieldOptions($field);

            $values[] = [
                'field_id'      => (int) ($field['id'] ?? $record['id'] ?? 0),
                'name'          => $name,
                'label'         => (string) ($field['label'] ?? $field['title'] ?? $name),
                'type'          => (string) ($field['type'] ?? ''),
                'raw_value'     => $attributes[$name],
                'display_value' => $this->resolveFieldDisplayValue($attributes[$name], $options),
            ];
        }

        return [
            'context' => $context,
            'item_id' => $itemId,
            'values'  => $values,
        ];
    }

    private function setItemFieldValues(array $params): array
    {
        $context = $this->requireFieldValueContext($params);
        $route = self::FIELD_VALUE_ITEM_ROUTES[$context];

        if ($route['writable'] !== true) {
            throw new \InvalidArgumentException(
                'Joomla\'s Web Services API cannot write custom field values for context ' . $context
                . ' — its API controller does not map field names onto com_fields. Writable contexts: '
                . implode(', ', $this->writableFieldValueContexts())
            );
        }

        $itemId = $this->requireFieldId($params, 'item_id');
        $values = $params['values'] ?? null;

        if (!is_array($values) || $values === []) {
            throw new \InvalidArgumentException('values is required and must name at least one field');
        }

        $known = [];

        foreach ($this->fetchAllFieldRecords($this->fieldsPath($context), $context) as $record) {
            $name = (string) ($record['attributes']['name'] ?? '');

            if ($name !== '') {
                $known[] = $name;
            }
        }

        // Joomla drops keys that do not match a field name without complaining, which would read
        // back to the caller as a successful no-op. Reject them before writing anything.
        $unknown = array_diff(array_keys($values), $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown field name(s) for context ' . $context . ': ' . implode(', ', $unknown)
                . '. Known field names: ' . ($known === [] ? '(none)' : implode(', ', $known))
            );
        }

        $this->rest->patch($route['path'] . '/' . $itemId, $values);
        $this->invalidateFieldValueItemCaches($context, $itemId);

        return $this->getItemFieldValues(['context' => $context, 'item_id' => $itemId]);
    }

    // --- com_fields helpers ----------------------------------------------

    private function requireFieldContext(array $params): string
    {
        $context = trim((string) ($params['context'] ?? ''));

        if ($context === '') {
            throw new \InvalidArgumentException('context is required');
        }

        if (!isset(self::FIELD_CONTEXT_ROUTES[$context])) {
            throw new \InvalidArgumentException(
                'Unknown field context "' . $context . '". Valid contexts: '
                . implode(', ', array_keys(self::FIELD_CONTEXT_ROUTES))
            );
        }

        return $context;
    }

    private function requireFieldValueContext(array $params): string
    {
        $context = $this->requireFieldContext($params);

        if (!isset(self::FIELD_VALUE_ITEM_ROUTES[$context])) {
            throw new \InvalidArgumentException(
                'Context ' . $context . ' stores no items, so it has no field values to read or write.'
                . ' Contexts with items: ' . implode(', ', array_keys(self::FIELD_VALUE_ITEM_ROUTES))
            );
        }

        return $context;
    }

    /** @return list<string> */
    private function writableFieldValueContexts(): array
    {
        $writable = [];

        foreach (self::FIELD_VALUE_ITEM_ROUTES as $context => $route) {
            if ($route['writable'] === true) {
                $writable[] = $context;
            }
        }

        return $writable;
    }

    private function fieldsPath(string $context): string
    {
        return 'api/index.php/v1/fields/' . self::FIELD_CONTEXT_ROUTES[$context];
    }

    private function fieldGroupsPath(string $context): string
    {
        return 'api/index.php/v1/fields/groups/' . self::FIELD_CONTEXT_ROUTES[$context];
    }

    private function requireFieldId(array $params, string $key = 'id'): int
    {
        $id = (int) ($params[$key] ?? 0);

        if ($id <= 0) {
            throw new \InvalidArgumentException($key . ' is required');
        }

        return $id;
    }

    private function requireFieldString(array $params, string $key): string
    {
        $value = trim((string) ($params[$key] ?? ''));

        if ($value === '') {
            throw new \InvalidArgumentException($key . ' is required');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>          $updatable
     */
    private function requireFieldUpdates(array $params, array $updatable): void
    {
        foreach ($updatable as $key) {
            if (array_key_exists($key, $params)) {
                return;
            }
        }

        throw new \InvalidArgumentException('No updatable fields supplied');
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>          $intFilters
     * @param  list<string>          $stringFilters
     * @return array<string, mixed>
     */
    private function fieldListQuery(array $params, array $intFilters = [], array $stringFilters = []): array
    {
        $query = $this->buildPageQuery($params);

        foreach ($intFilters as $key) {
            if (isset($params[$key])) {
                $query['filter[' . $key . ']'] = (int) $params[$key];
            }
        }

        foreach ($stringFilters as $key) {
            if (isset($params[$key]) && (string) $params[$key] !== '') {
                $query['filter[' . $key . ']'] = (string) $params[$key];
            }
        }

        return $query;
    }

    /**
     * Copy only the keys the caller actually supplied, cast to their column type, so an omitted
     * key is left to Joomla's own PATCH backfill rather than being reset to a default.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>          $stringColumns
     * @param  list<string>          $intColumns
     * @return array<string, mixed>
     */
    private function buildFieldPayload(array $params, array $stringColumns, array $intColumns): array
    {
        $payload = [];

        foreach ($stringColumns as $key) {
            if (array_key_exists($key, $params)) {
                $payload[$key] = (string) $params[$key];
            }
        }

        foreach ($intColumns as $key) {
            if (array_key_exists($key, $params)) {
                $payload[$key] = (int) $params[$key];
            }
        }

        return $payload;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function normaliseAssignedCatIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFieldResponse(string $path, string $label, string $context, int $id): array
    {
        try {
            return $this->rest->get($path . '/' . $id);
        } catch (\Throwable $e) {
            if ($this->fieldResponseStatus($e) === 404) {
                throw new \InvalidArgumentException(
                    $label . ' ' . $id . ' was not found in context ' . $context,
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function fieldAttributes(array $response, string $label, int $id): array
    {
        $attributes = $response['data']['attributes'] ?? null;

        if (!is_array($attributes)) {
            throw new \RuntimeException(
                'The Joomla Web Services API returned no attributes for ' . $label . ' ' . $id
            );
        }

        return $attributes;
    }

    /**
     * A 404 on a fields collection cannot mean "record missing" — an empty collection is a 200 —
     * so it is always a missing route, and the fix is enabling a plugin.
     *
     * @template TReturn
     * @param  callable(): TReturn  $call
     * @return TReturn
     */
    private function withFieldRouteErrors(string $context, callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if ($this->fieldResponseStatus($e) !== 404) {
                throw $e;
            }

            $component = explode('.', $context)[0];
            $plugin = self::FIELD_CONTEXT_PLUGINS[$component] ?? null;

            throw new \RuntimeException(
                'The Joomla Web Services API has no custom fields route for context ' . $context . '.'
                . ($plugin === null
                    ? ''
                    : ' Core registers it from the ' . $plugin . ' plugin, so enable that plugin in'
                        . ' the Joomla plugin manager.'),
                0,
                $e
            );
        }
    }

    private function fieldResponseStatus(\Throwable $e): ?int
    {
        return $e instanceof RequestException && $e->hasResponse()
            ? $e->getResponse()->getStatusCode()
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllFieldRecords(string $path, string $context): array
    {
        return $this->cache->remember(
            'fields_all:' . $path,
            fn () => $this->withFieldRouteErrors($context, fn () => $this->fetchAllPages($path))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFieldValueItem(string $context, int $itemId): array
    {
        $route = self::FIELD_VALUE_ITEM_ROUTES[$context];

        try {
            $response = $this->rest->get($route['path'] . '/' . $itemId);
        } catch (\Throwable $e) {
            if ($this->fieldResponseStatus($e) === 404) {
                throw new \InvalidArgumentException(
                    'No item with id ' . $itemId . ' exists for context ' . $context,
                    0,
                    $e
                );
            }

            throw $e;
        }

        $attributes = $response['data']['attributes'] ?? null;

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * Joomla only deletes trashed fields and field groups (both canDelete() implementations
     * require state -2), so trash first when needed — the same shape as delete_article.
     *
     * @return array<string, mixed>
     */
    private function deleteFieldRecord(string $path, string $label, string $context, int $id): array
    {
        $existing = $this->fieldAttributes(
            $this->fetchFieldResponse($path, $label, $context, $id),
            strtolower($label),
            $id
        );

        if ((int) ($existing['state'] ?? 0) !== -2) {
            $this->rest->patch($path . '/' . $id, ['context' => $context, 'state' => -2]);
        }

        $result = $this->rest->delete($path . '/' . $id);
        $this->invalidateFieldCaches();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function reorderFieldRecords(array $params, string $path, string $label, string $context): array
    {
        $requested = $params['ordered_ids'] ?? null;

        if (!is_array($requested) || $requested === []) {
            throw new \InvalidArgumentException('ordered_ids is required and must list at least one id');
        }

        $ordered = [];

        foreach ($requested as $value) {
            $id = (int) $value;

            if ($id <= 0) {
                throw new \InvalidArgumentException('ordered_ids must contain positive integer ids');
            }

            if (in_array($id, $ordered, true)) {
                throw new \InvalidArgumentException('ordered_ids contains id ' . $id . ' more than once');
            }

            $ordered[] = $id;
        }

        $attributesById = [];

        foreach ($this->fetchAllFieldRecords($path, $context) as $record) {
            $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
            $id = (int) ($attributes['id'] ?? $record['id'] ?? 0);

            if ($id > 0) {
                $attributesById[$id] = $attributes;
            }
        }

        // Validate the whole list up front so one bad id cannot leave the ordering half-applied.
        $unknown = array_diff($ordered, array_keys($attributesById));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                $label . ' id(s) not found in context ' . $context . ': ' . implode(', ', $unknown)
            );
        }

        $reordered = [];

        foreach ($ordered as $position => $id) {
            $payload = ['context' => $context, 'ordering' => $position + 1];

            // Same trap as update_field: a PATCH that omits assigned_cat_ids makes FieldModel
            // delete every category assignment, and reordering must not touch them.
            if (array_key_exists('assigned_cat_ids', $attributesById[$id])) {
                $payload['assigned_cat_ids'] = $this->normaliseAssignedCatIds($attributesById[$id]['assigned_cat_ids']);
            }

            $this->rest->patch($path . '/' . $id, $payload);

            $reordered[] = ['id' => $id, 'ordering' => $position + 1];
        }

        $this->invalidateFieldCaches();

        return [
            'context'   => $context,
            'reordered' => $reordered,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function decorateFieldRecord(array $attributes): array
    {
        $attributes['options'] = $this->expandFieldOptions($attributes);

        return $attributes;
    }

    /**
     * list, radio and checkboxes fields store only the option value; the human-readable label
     * lives in fieldparams.options and is renameable in the admin. Surface the two separately
     * instead of making callers parse fieldparams, leaving the raw fieldparams alongside.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{value:string, label:string}>
     */
    private function expandFieldOptions(array $attributes): array
    {
        $options = $this->decodeFieldRegistry($attributes['fieldparams'] ?? null)['options'] ?? null;

        if (!is_array($options)) {
            return [];
        }

        $expanded = [];

        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                continue;
            }

            $expanded[] = [
                'value' => (string) $option['value'],
                'label' => (string) ($option['name'] ?? $option['value']),
            ];
        }

        return $expanded;
    }

    /**
     * Anything without options — and any value whose option has since been removed — displays
     * exactly as it is stored. Multi-value fields arrive as arrays and map element by element.
     *
     * @param  list<array{value:string, label:string}>  $options
     */
    private function resolveFieldDisplayValue(mixed $raw, array $options): mixed
    {
        if ($options === []) {
            return $raw;
        }

        $labels = array_column($options, 'label', 'value');

        if (is_array($raw)) {
            return array_map(static fn ($value) => $labels[(string) $value] ?? $value, $raw);
        }

        if (is_scalar($raw)) {
            return $labels[(string) $raw] ?? $raw;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFieldRegistry(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Shallow merge, matching update_module: a nested object is replaced wholesale rather than
     * deep-merged, so changing one nested key means sending that whole nested object.
     *
     * @param  array<string, mixed>  $supplied
     * @return array<string, mixed>
     */
    private function mergeFieldRegistry(mixed $existing, array $supplied): array
    {
        return array_merge($this->decodeFieldRegistry($existing), $supplied);
    }

    /**
     * Field values are embedded in the owning item's own API representation — the article
     * JsonapiView copies every field onto the item, for single reads and for lists alike — so
     * changing a field definition or a stored value makes cached item responses stale. Pass an id
     * when only one item changed; omit it when a definition change could affect any of them.
     *
     * com_content.article is the only context with cached read tools today (there are no contact
     * or user tools, and category field values are not writable), so it is the only case here.
     */
    private function invalidateFieldValueItemCaches(string $context, ?int $itemId = null): void
    {
        if ($context !== 'com_content.article') {
            return;
        }

        if ($itemId === null) {
            $this->cache->deleteByPrefix('article:');
        } else {
            $this->cache->delete('article:' . $itemId);
        }

        // Lists carry field values too: JsonapiView::displayList() adds every field name to
        // fieldsToRenderList and prepareItem() fills them in per row.
        $this->cache->deleteByPrefix('articles_search:');
    }

    private function invalidateFieldCaches(): void
    {
        $this->cache->deleteByPrefix('fields_list:');
        $this->cache->deleteByPrefix('field_groups_list:');
        $this->cache->deleteByPrefix('fields_all:');
    }

}

