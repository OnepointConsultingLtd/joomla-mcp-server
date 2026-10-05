# MCP Optional Features — Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Phase A optional parts of MCP — server/tool metadata and icon, `completion/complete`,
`x-mcp-header` parameter headers and live `notifications/progress` with cooperative cancellation.
Every addition works across both protocol eras where the spec allows.

**Architecture:** The pure logic lives in small, unit-tested classes in `admin/src/Service/`:

- `CompletionService`
- `ProgressReporter`
- `ProgressSink`
- `HttpProgressSink`
- `ProgressCancelled`
- new `McpHttpTransport` methods

`RpcService` uses them. `RpcHandlerTrait` only wires them to `header()`, `echo` and `flush()`. The
stdio bridge (`site/mcp-http-bridge.js`) learns to relay event streams incrementally and to mirror
`Mcp-Param-*` headers.

**Tech Stack:**

| Area | Version |
|---|---|
| PHP | 8.1+ (CI runs 8.1; local is 8.5) |
| PHPUnit | 10.5 |
| Guzzle | 7 (existing `RestClient`) |
| Node | ≥18 for the bridge runtime; `node:test` on Node 24 in CI |
| Python | 3.9+ eval client |

**Spec:** `docs/superpowers/specs/2026-10-01-mcp-optional-phase-a-design.md`. Read it before
starting; this plan implements it.

## Global Constraints

- **PHP files.** Every PHP file opens with the project docblock header,
  `declare(strict_types=1);` and `defined('_JEXEC') or die;`. Copy the header verbatim from an
  existing file such as `admin/src/Service/JsonRpc.php`.
- **PHP 8.1 syntax only.** That means readonly *promoted properties*, not `readonly class`; no
  typed class constants; no `never`-returning closures in production code.
- **Bridge runtime.** It must run on Node 18: no `fetch`-only APIs, no top-level `await`.
- **Comments** explain *why*, never *what* (project convention).
- **Messages shown to callers** never include URLs, cURL text, SQL or paths. Use
  `RpcService::clientErrorMessage()` for any caught throwable.
- **Never call `reportProgress()` after a mutation has been committed.** A cancellation raised
  there would report a completed change as cancelled.
- **Icon URL:** `Uri::root() . 'components/com_mcpserver/icon.png'`, `mimeType` `image/png`,
  `sizes` `["64x64"]`.
- **`x-mcp-header` map:** `id`→`Id`, `version_id`→`Version-Id`, `extension_id`→`Extension-Id`,
  `catid`→`Catid`. Applied to top-level `integer` properties only.
- **Completions:** at most 100 values; `argument.value` at most 200 characters.
- **Progress:** throttled at 0.25 s; `progress` strictly increasing; a notification with
  `progress >= total` is always sent.
- **Cancelled tool calls** are audited with HTTP status `499` and status `error`.
- **Commits:** the user commits only on request. Run a task's commit step only once the user has
  authorised commits for this work; otherwise leave the changes staged and move on.

## Review Focus

1. **Hosts with PHP output buffering or `zlib.output_compression` on.** Progress must still reach
   the client live, not all at once at the end. The test is in Task 12: e2e run with
   `-d output_buffering=4096 -d zlib.output_compression=1`.
2. **Completion values that are long, empty or full of wildcards** (`%`, `_`, 10 000 characters).
   Expected: over 200 characters gives `-32602`; wildcards are matched literally; an empty value
   lists the first 100. Tests are in Tasks 2 and 3.
3. **A `progressToken` of the wrong type** (array, boolean, float). Expected: no progress at all,
   and the call still succeeds normally. Test in Task 7.
4. **Equivalent numeric spellings in `Mcp-Param-*` headers** (`05`, `5.0`, ` 5`) for argument
   `5`. Expected: accepted as equal. A different number, or non-numeric text, is rejected. Test in
   Task 5.
5. **A client disconnecting during a mutating tool.** Expected: the work stops only *before* any
   change is committed, never after one. Test in Task 7: `install_extension` cancelled at its first
   progress point never reaches the installer.

---

### Task 1: Server and tool metadata, icon, resource `lastModified`

**Files:**
- Modify: `admin/src/Service/ToolRegistry.php` (`register()`)
- Modify: `admin/src/Service/RpcService.php`:
  - new constants
  - `serverIdentity()`
  - `handleCapabilities()`
  - `handleDiscover()`
  - `mapArticleToResource()`
  - new `isoTimestamp()`
  - new `use Joomla\CMS\Uri\Uri;`
- Modify: `admin/src/Service/McpProtocol.php` (`completeResult()`)
- Create: `site/icon.png`
- Modify: `mcpserver.xml` (site `<files>`)
- Create: `tests/Unit/ToolRegistryTest.php`
- Modify:
  - `tests/Unit/RpcServiceModernProtocolTest.php`
  - `tests/Unit/McpProtocolTest.php`
  - `tests/Unit/RpcServiceResourcesTest.php`

**Interfaces:**
- Produces:
  - `RpcService::serverIdentity(): array`, private: `serverInfo()` plus `title`,
    `description`, `websiteUrl` and `icons`.
  - `McpProtocol::completeResult()` now keeps an existing `_meta[serverInfo]`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ToolRegistryTest.php`:

```php
<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function testEveryAnnotationTitleBecomesTheTopLevelTitle(): void
    {
        foreach ((new ToolRegistry())->getAll() as $tool) {
            if (isset($tool['annotations']['title'])) {
                $this->assertSame($tool['annotations']['title'], $tool['title'] ?? null, $tool['name']);
            }
        }
    }

    public function testAnExplicitTitleIsKept(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'custom',
            'title' => 'Own title',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['title' => 'Annotation title'],
        ]);

        $this->assertSame('Own title', $registry->get('custom')['title']);
    }
}
```

Append to `tests/Unit/RpcServiceModernProtocolTest.php`, before the private helpers:

```php
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
```

Append to `tests/Unit/McpProtocolTest.php`:

```php
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
```

Append to `tests/Unit/RpcServiceResourcesTest.php`:

```php
    public function testListResourcesAnnotatesLastModified(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            ['id' => 3, 'attributes' => ['title' => 'A', 'alias' => 'a', 'introtext' => '', 'modified' => '2026-09-01 10:00:00']],
            ['id' => 2, 'attributes' => ['title' => 'B', 'alias' => 'b', 'introtext' => '', 'modified' => '0000-00-00 00:00:00']],
            ['id' => 1, 'attributes' => ['title' => 'C', 'alias' => 'c', 'introtext' => '']],
        ]]);

        $resources = $this->makeService($rest, true)->handle($this->rpc('resources/list'))['result']['resources'];

        $this->assertSame(['lastModified' => '2026-09-01T10:00:00+00:00'], $resources[0]['annotations']);
        $this->assertArrayNotHasKey('annotations', $resources[1], 'a zero date is no date');
        $this->assertArrayNotHasKey('annotations', $resources[2]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
admin/vendor/bin/phpunit tests/Unit/ToolRegistryTest.php tests/Unit/RpcServiceModernProtocolTest.php tests/Unit/McpProtocolTest.php tests/Unit/RpcServiceResourcesTest.php
```

Expected: FAIL. The cause is the missing `title` key, the missing `_meta` serverInfo title or
`icons`, and the missing `annotations`.

- [ ] **Step 3: Implement**

In `ToolRegistry::register()`, replace the body:

```php
    public function register(array $tool): void
    {
        // Display precedence is title, then annotations.title, then name; clients
        // from 2025-06-18 on read the top-level field first.
        if (!isset($tool['title']) && is_string($tool['annotations']['title'] ?? null)) {
            $tool['title'] = $tool['annotations']['title'];
        }

        $this->tools[$tool['name']] = $tool;
    }
```

In `McpProtocol::completeResult()`, change `$meta[self::META_SERVER_INFO] = $serverInfo;` to:

```php
        // A result may already carry the fuller identity (server/discover does).
        $meta[self::META_SERVER_INFO] ??= $serverInfo;
```

In `RpcService`:

1. Add `use Joomla\CMS\Uri\Uri;` to the imports.
2. Add these constants next to `ARTICLE_RESOURCE_URI_PREFIX`:

```php
    private const SERVER_TITLE = 'MCP Server for Joomla';

    private const SERVER_DESCRIPTION = "Manage a Joomla site's content, structure and configuration through its Web Services API.";

    private const SERVER_WEBSITE = 'https://github.com/OnepointConsultingLtd/joomla-mcp-server';
```

3. Add the method below `serverInfo()`:

```php
    /**
     * The full self-description, for initialize and server/discover. Every other
     * modern result carries only serverInfo() in its _meta, keeping responses small.
     *
     * @return array<string, mixed>
     */
    private function serverIdentity(): array
    {
        return $this->serverInfo() + [
            'title' => self::SERVER_TITLE,
            'description' => self::SERVER_DESCRIPTION,
            'websiteUrl' => self::SERVER_WEBSITE,
            'icons' => [[
                'src' => Uri::root() . 'components/com_mcpserver/icon.png',
                'mimeType' => 'image/png',
                'sizes' => ['64x64'],
            ]],
        ];
    }
```

4. In `handleCapabilities()`, change `'serverInfo' => $this->serverInfo(),` to
   `'serverInfo' => $this->serverIdentity(),`.
5. In `handleDiscover()`, add `'_meta' => [McpProtocol::META_SERVER_INFO => $this->serverIdentity()],`
   to the result array.
6. In `mapArticleToResource()`, replace the final `return [...]` with:

```php
        $resource = [
            'uri' => self::ARTICLE_RESOURCE_URI_PREFIX . $id,
            'name' => $alias !== '' ? $alias : 'article-' . $id,
            'title' => (string) ($attributes['title'] ?? ''),
            'description' => $intro,
            'mimeType' => 'text/html',
        ];

        $modified = $this->isoTimestamp($attributes['modified'] ?? null);
        if ($modified !== null) {
            $resource['annotations'] = ['lastModified' => $modified];
        }

        return $resource;
    }

    /**
     * Joomla stores datetimes as UTC SQL strings, and writes a zero date for
     * "never"; MCP annotations want ISO 8601.
     */
    private function isoTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
```

(The `}` closing `isoTimestamp()` is the original closing brace of `mapArticleToResource()`.)

- [ ] **Step 4: Generate the icon and ship it**

Run:

```bash
docker run --rm -v "$PWD":/w -w /w alpine:3 sh -c "apk add -q imagemagick >/dev/null && magick assets/logo.png -resize 64x64 -strip -define png:compression-level=9 site/icon.png" && file site/icon.png
```

Expected: `site/icon.png: PNG image data, 64 x 64`.

In `mcpserver.xml`, inside `<files folder="site">`, add `<filename>icon.png</filename>` after
`<filename>mcp-http-bridge.js</filename>`. `build.sh` already rsyncs `site/` whole.

- [ ] **Step 5: Run the tests**

Run: `admin/vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: everything passes. The 7 deprecations are pre-existing.

- [ ] **Step 6: Commit** (only if commits are authorised)

```bash
git add admin/src/Service/ToolRegistry.php admin/src/Service/RpcService.php admin/src/Service/McpProtocol.php site/icon.png mcpserver.xml tests/Unit/ToolRegistryTest.php tests/Unit/RpcServiceModernProtocolTest.php tests/Unit/McpProtocolTest.php tests/Unit/RpcServiceResourcesTest.php
git commit -m "feat: add server identity, icon, tool titles and resource lastModified"
```

---

### Task 2: `CompletionService` (pure matching)

**Files:**
- Create: `admin/src/Service/CompletionService.php`
- Test: `tests/Unit/CompletionServiceTest.php`

**Interfaces:**
- Produces:
  - `CompletionService::MAX_VALUES = 100`
  - `CompletionService::complete(array $candidates, string $typed): array{values: list<string>, total: int, hasMore: bool}`

- [ ] **Step 1: Write the failing test**

```php
<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CompletionService;
use PHPUnit\Framework\TestCase;

class CompletionServiceTest extends TestCase
{
    public function testPrefixMatchesComeBeforeSubstringMatches(): void
    {
        $result = CompletionService::complete(['Kitchen Garden', 'Garden', 'News', 'Gardening tips'], 'gar');

        $this->assertSame(['values' => ['Garden', 'Gardening tips', 'Kitchen Garden'], 'total' => 3, 'hasMore' => false], $result);
    }

    public function testMatchingIgnoresCase(): void
    {
        $this->assertSame(['fr-FR'], CompletionService::complete(['fr-FR', 'de-DE'], 'FR')['values']);
    }

    public function testEmptyValueListsEverything(): void
    {
        $this->assertSame(['b', 'a'], CompletionService::complete(['b', 'a'], '')['values']);
    }

    public function testResultsAreCappedAtOneHundredWithTotalAndHasMore(): void
    {
        $candidates = array_map(static fn (int $n): string => 'item ' . $n, range(1, 150));

        $result = CompletionService::complete($candidates, 'item');

        $this->assertCount(100, $result['values']);
        $this->assertSame(150, $result['total']);
        $this->assertTrue($result['hasMore']);
    }

    public function testDuplicatesAndEmptyCandidatesAreDropped(): void
    {
        $this->assertSame(['x'], CompletionService::complete(['x', '', 'x'], '')['values']);
    }

    public function testWildcardCharactersMatchLiterally(): void
    {
        $this->assertSame(['100% organic'], CompletionService::complete(['100% organic', '100 percent'], '100%')['values']);
        $this->assertSame([], CompletionService::complete(['abc'], 'a_c')['values']);
    }

    public function testNoMatchIsAnEmptyCompletion(): void
    {
        $this->assertSame(['values' => [], 'total' => 0, 'hasMore' => false], CompletionService::complete(['a'], 'zzz'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `admin/vendor/bin/phpunit tests/Unit/CompletionServiceTest.php`

Expected: errors with `Class "...CompletionService" not found`.

- [ ] **Step 3: Implement**

`admin/src/Service/CompletionService.php`, with the project header, namespace
`Joomla\Component\Mcpserver\Administrator\Service` and `defined('_JEXEC') or die;`:

```php
/**
 * Ranks completion candidates for completion/complete: prefix matches first,
 * then substring matches, case-insensitively and literally (the typed value is
 * never a pattern), capped at the 100 values the protocol allows.
 */
final class CompletionService
{
    public const MAX_VALUES = 100;

    /**
     * @param  list<string>  $candidates
     * @return array{values: list<string>, total: int, hasMore: bool}
     */
    public static function complete(array $candidates, string $typed): array
    {
        $needle = mb_strtolower($typed);
        $prefix = [];
        $contains = [];

        foreach (array_unique($candidates) as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $haystack = mb_strtolower($candidate);
            if ($needle === '' || str_starts_with($haystack, $needle)) {
                $prefix[] = $candidate;
            } elseif (str_contains($haystack, $needle)) {
                $contains[] = $candidate;
            }
        }

        $matches = [...$prefix, ...$contains];

        return [
            'values' => array_slice($matches, 0, self::MAX_VALUES),
            'total' => count($matches),
            'hasMore' => count($matches) > self::MAX_VALUES,
        ];
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `admin/vendor/bin/phpunit tests/Unit/CompletionServiceTest.php`

Expected: OK (7 tests).

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/CompletionService.php tests/Unit/CompletionServiceTest.php
git commit -m "feat: add CompletionService ranking"
```

---

### Task 3: `completion/complete` in `RpcService` and the `completions` capability

**Files:**
- Modify: `admin/src/Service/RpcService.php`:
  - `dispatchCommon()`
  - `buildServerCapabilities()`
  - new methods `handleComplete`, `promptCompletionSource`, `resourceCompletionSource`,
    `completeArticleIds`, `articleIds`, `idsOf`, `categoryTitles`, `contentLanguageCodes`
  - new constants
- Create: `tests/Unit/RpcServiceCompletionsTest.php`
- Modify: `tests/Unit/RpcServiceModernProtocolTest.php`
  (`testDiscoverCapabilitiesFollowPolicy`)

**Interfaces:**
- Consumes: `CompletionService::complete()` (Task 2); existing `fetchAllPages()`,
  `clientErrorMessage()`, `CacheService::remember()`.
- Produces:
  - the `completion/complete` method, in both eras;
  - capability `completions` (encoded as `{}`) when prompts or resources are enabled.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/RpcServiceCompletionsTest.php`:

```php
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

        $this->assertSame(['3'], $completion['values']);
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
```

In `tests/Unit/RpcServiceModernProtocolTest.php`, replace the assertion in
`testDiscoverCapabilitiesFollowPolicy`. `completions` must encode as `{}`, so compare the JSON:

```php
        $this->assertSame(
            '{"tools":{"listChanged":false},"resources":{"subscribe":false,"listChanged":false},"prompts":{"listChanged":false},"completions":{}}',
            json_encode($response['result']['capabilities'])
        );
```

- [ ] **Step 2: Run them to verify they fail**

Run:

```bash
admin/vendor/bin/phpunit tests/Unit/RpcServiceCompletionsTest.php tests/Unit/RpcServiceModernProtocolTest.php
```

Expected: FAIL. The method is not found (`-32601`) and the capability JSON has no `completions`.

- [ ] **Step 3: Implement**

In `RpcService`, add these constants next to `ARTICLE_RESOURCE_URI_PREFIX`:

```php
    private const ARTICLE_TEMPLATE_URI = 'joomla://article/{id}';

    private const COMPLETION_VALUE_MAX_LENGTH = 200;

    /** Pages of articles scanned for ID completion: newest-first, up to 1000 IDs. */
    private const COMPLETION_ARTICLE_PAGES = 10;
```

In `dispatchCommon()`, add an arm before `'site_health'`:

```php
            'completion/complete' => $this->policy->promptsEnabled() || $this->policy->resourcesEnabled()
                ? $this->handleComplete($id, $params)
                : null,
```

In `buildServerCapabilities()`, before `return $capabilities;`, add the block below and change the
docblock return type to `@return array<string, mixed>`:

```php
        // Completions exist only for prompt arguments and the article template.
        if ($this->policy->resourcesEnabled() || $this->policy->promptsEnabled()) {
            $capabilities['completions'] = new \stdClass();
        }
```

Add the methods (after `handleSubscriptionsListen()`):

```php
    /**
     * @param  array<string, mixed>  $params
     */
    private function handleComplete(mixed $id, array $params): array
    {
        $ref = $params['ref'] ?? null;
        $argument = $params['argument'] ?? null;

        if (!is_array($ref) || !is_string($ref['type'] ?? null)) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'ref must be an object whose type is ref/prompt or ref/resource');
        }
        if (!is_array($argument) || !is_string($argument['name'] ?? null) || !is_string($argument['value'] ?? null)) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'argument must be an object with a string name and a string value');
        }
        if (mb_strlen($argument['value']) > self::COMPLETION_VALUE_MAX_LENGTH) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, 'argument.value must be at most 200 characters');
        }

        $source = match ($ref['type']) {
            'ref/prompt' => $this->promptCompletionSource($ref, $argument['name']),
            'ref/resource' => $this->resourceCompletionSource($ref, $argument['name']),
            default => 'Unknown ref type: use ref/prompt or ref/resource',
        };
        if (is_string($source)) {
            return JsonRpc::errorResponse($id, JsonRpc::INVALID_PARAMS, $source);
        }

        try {
            return JsonRpc::successResponse($id, ['completion' => $source($argument['value'])]);
        } catch (\Throwable $e) {
            $this->logger->error('Completion failed', ['ref' => $ref['type'], 'argument' => $argument['name'], 'error' => $e->getMessage()]);

            return JsonRpc::errorResponse($id, JsonRpc::INTERNAL_ERROR, $this->clientErrorMessage($e, 'Completion failed due to an internal error'));
        }
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return callable(string): array<string, mixed>|string  the candidate source, or why there is none
     */
    private function promptCompletionSource(array $ref, string $argument): callable|string
    {
        if (!$this->policy->promptsEnabled()) {
            return 'Prompts are disabled by server policy';
        }

        $name = $ref['name'] ?? null;
        $prompt = is_string($name) ? $this->promptRegistry->get($name) : null;
        if ($prompt === null) {
            return 'Unknown prompt. Prompts: ' . implode(', ', array_column($this->promptRegistry->getAll(), 'name'));
        }

        $defined = array_column($prompt['arguments'] ?? [], 'name');
        if (!in_array($argument, $defined, true)) {
            return "Prompt {$name} has no argument '{$argument}'. Its arguments: " . implode(', ', $defined);
        }

        return match ($argument) {
            'category' => fn (string $typed): array => CompletionService::complete($this->categoryTitles(), $typed),
            'article_id' => fn (string $typed): array => $this->completeArticleIds($typed),
            'target_language' => fn (string $typed): array => CompletionService::complete($this->contentLanguageCodes(), $typed),
            // Free text (topic): there is nothing to suggest.
            default => static fn (string $typed): array => CompletionService::complete([], $typed),
        };
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return callable(string): array<string, mixed>|string
     */
    private function resourceCompletionSource(array $ref, string $argument): callable|string
    {
        if (!$this->policy->resourcesEnabled()) {
            return 'Resources are disabled by server policy';
        }
        if (($ref['uri'] ?? null) !== self::ARTICLE_TEMPLATE_URI) {
            return 'Unknown resource template. Completable template: ' . self::ARTICLE_TEMPLATE_URI;
        }
        if ($argument !== 'id') {
            return 'Resource template ' . self::ARTICLE_TEMPLATE_URI . " has no argument '{$argument}'. Its arguments: id";
        }

        return fn (string $typed): array => $this->completeArticleIds($typed);
    }

    /**
     * Digits complete an article ID by prefix. Anything else is a title search
     * whose matches are offered by ID, because the ID is what the argument takes.
     *
     * @return array{values: list<string>, total: int, hasMore: bool}
     */
    private function completeArticleIds(string $typed): array
    {
        if ($typed === '' || ctype_digit($typed)) {
            return CompletionService::complete($this->articleIds(), $typed);
        }

        $response = $this->cache->remember(
            'articles_search:completion:' . md5($typed),
            fn () => $this->rest->get('api/index.php/v1/content/articles', [
                'filter[search]' => $typed,
                'page[limit]' => CompletionService::MAX_VALUES + 1,
            ])
        );

        return CompletionService::complete($this->idsOf(is_array($response['data'] ?? null) ? $response['data'] : []), '');
    }

    /**
     * The articles_search: prefix means article writes already invalidate it.
     *
     * @return list<string>
     */
    private function articleIds(): array
    {
        return $this->cache->remember(
            'articles_search:completion:ids',
            fn (): array => $this->idsOf($this->fetchAllPages('api/index.php/v1/content/articles', [], 100, self::COMPLETION_ARTICLE_PAGES))
        );
    }

    /**
     * Newest first: higher IDs are more recent articles.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<string>
     */
    private function idsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        rsort($ids);

        return array_map('strval', $ids);
    }

    /**
     * @return list<string>
     */
    private function categoryTitles(): array
    {
        return $this->cache->remember('categories_list:completion', function (): array {
            $titles = [];
            foreach ($this->fetchAllPages(self::CATEGORIES_PATH, ['filter[published]' => 1]) as $row) {
                $title = is_array($row) ? ($row['attributes']['title'] ?? null) : null;
                if (is_string($title)) {
                    $titles[] = $title;
                }
            }

            return $titles;
        });
    }

    /**
     * @return list<string>
     */
    private function contentLanguageCodes(): array
    {
        return $this->cache->remember('content_languages:completion', function (): array {
            $codes = [];
            foreach ($this->fetchAllPages(self::CONTENT_LANGUAGES_PATH, ['filter[published]' => 1]) as $row) {
                $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : (is_array($row) ? $row : []);
                if (is_string($attributes['lang_code'] ?? null)) {
                    $codes[] = $attributes['lang_code'];
                }
            }

            return $codes;
        });
    }
```

- [ ] **Step 4: Run the tests**

Run: `admin/vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: all tests pass.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/RpcService.php tests/Unit/RpcServiceCompletionsTest.php tests/Unit/RpcServiceModernProtocolTest.php
git commit -m "feat: add completion/complete for prompt arguments and the article template"
```

---

### Task 4: `x-mcp-header` annotations on tool schemas

**Files:**
- Modify: `admin/src/Service/ToolRegistry.php` (constant `PARAM_HEADERS`, `register()`, new
  `paramHeaders()`)
- Modify: `tests/Unit/ToolRegistryTest.php`
- Modify: `tests/Unit/ToolSchemaDialectTest.php` (`PORTABLE_KEYWORDS`)

**Interfaces:**
- Produces: `ToolRegistry::paramHeaders(string $name): array<string, string>`. It maps property
  name to header name, e.g. `['id' => 'Id']`, and returns `[]` for an unknown tool.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/ToolRegistryTest.php`:

```php
    public function testTargetIdsAreAnnotatedOnFortyEightTools(): void
    {
        $annotated = array_filter(
            (new ToolRegistry())->getAll(),
            static fn (array $tool): bool => (new ToolRegistry())->paramHeaders($tool['name']) !== []
        );

        $this->assertCount(48, $annotated);
    }

    public function testOnlyTopLevelIntegersAreAnnotatedWithValidUniqueNames(): void
    {
        $registry = new ToolRegistry();
        foreach ($registry->getAll() as $tool) {
            $headers = $registry->paramHeaders($tool['name']);
            foreach ($headers as $property => $header) {
                $this->assertSame('integer', $tool['inputSchema']['properties'][$property]['type'], "{$tool['name']}.{$property}");
                $this->assertMatchesRegularExpression('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $header);
            }
            $lower = array_map('strtolower', array_values($headers));
            $this->assertSame($lower, array_values(array_unique($lower)), "{$tool['name']}: header names must be unique");
        }
    }

    public function testParamHeadersForAKnownTool(): void
    {
        $this->assertSame(['id' => 'Id'], (new ToolRegistry())->paramHeaders('get_article_by_id'));
        $this->assertSame([], (new ToolRegistry())->paramHeaders('no_such_tool'));
    }

    public function testANonIntegerTargetIsNotAnnotated(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'by_slug',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ]);
        $registry->register(['name' => 'no_params', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]);

        $this->assertSame([], $registry->paramHeaders('by_slug'));
        $this->assertSame([], $registry->paramHeaders('no_params'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `admin/vendor/bin/phpunit tests/Unit/ToolRegistryTest.php`

Expected: an `undefined method ToolRegistry::paramHeaders()` error.

- [ ] **Step 3: Implement**

In `ToolRegistry`, add above `$tools`:

```php
    /**
     * Tool parameters mirrored into Mcp-Param-{name} headers (x-mcp-header): the
     * identifier keys the audit trail records as a call's target, so gateways can
     * route or police on the same value without parsing bodies. Only top-level
     * integers qualify; the spec forbids number, nested and composed properties.
     */
    private const PARAM_HEADERS = [
        'id' => 'Id',
        'version_id' => 'Version-Id',
        'extension_id' => 'Extension-Id',
        'catid' => 'Catid',
    ];
```

In `register()`, before `$this->tools[$tool['name']] = $tool;`, insert:

```php
        $properties = $tool['inputSchema']['properties'] ?? null;
        if (is_array($properties)) {
            foreach (self::PARAM_HEADERS as $property => $header) {
                if (is_array($properties[$property] ?? null) && ($properties[$property]['type'] ?? null) === 'integer') {
                    $tool['inputSchema']['properties'][$property]['x-mcp-header'] = $header;
                }
            }
        }
```

Add the method after `get()`:

```php
    /**
     * @return array<string, string>  property name => Mcp-Param header name
     */
    public function paramHeaders(string $name): array
    {
        $headers = [];
        foreach ((array) ($this->tools[$name]['inputSchema']['properties'] ?? []) as $property => $schema) {
            if (is_array($schema) && is_string($schema['x-mcp-header'] ?? null)) {
                $headers[(string) $property] = $schema['x-mcp-header'];
            }
        }

        return $headers;
    }
```

In `tests/Unit/ToolSchemaDialectTest.php`, add `'x-mcp-header'` to `PORTABLE_KEYWORDS` and extend
the docblock line: "…plus `x-mcp-header`, an annotation that validators ignore."

- [ ] **Step 4: Run the tests**

Run: `admin/vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: all tests pass.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/ToolRegistry.php tests/Unit/ToolRegistryTest.php tests/Unit/ToolSchemaDialectTest.php
git commit -m "feat: annotate audit target ids with x-mcp-header"
```

---

### Task 5: Server-side `Mcp-Param-*` validation

**Files:**
- Modify: `admin/src/Service/McpHttpTransport.php` (new `validateParamHeaders()`,
  `sameParamValue()`)
- Modify: `admin/src/Controller/RpcHandlerTrait.php` (call it after `validate()`, new
  `paramHeadersFor()`)
- Modify: `tests/Unit/McpHttpTransportTest.php`, `tests/Unit/RpcHandlerTransportTest.php`

**Interfaces:**
- Consumes: `ToolRegistry::paramHeaders()` (Task 4); `McpHttpTransport::decodeHeaderValue()`,
  `mismatch()`.
- Produces:
  `McpHttpTransport::validateParamHeaders(array $request, array $headers, array $paramHeaders): void`,
  which throws `McpProtocolError` (`-32020`, 400).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/McpHttpTransportTest.php`:

```php
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
```

Append to `tests/Unit/RpcHandlerTransportTest.php`:

```php
    public function testParamHeadersComeFromTheToolsSchema(): void
    {
        $this->assertSame(['id' => 'Id'], $this->invoke('paramHeadersFor', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_article_by_id']]));
        $this->assertSame([], $this->invoke('paramHeadersFor', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run:

```bash
admin/vendor/bin/phpunit tests/Unit/McpHttpTransportTest.php tests/Unit/RpcHandlerTransportTest.php
```

Expected: errors for the undefined method `validateParamHeaders` and the missing method
`paramHeadersFor`.

- [ ] **Step 3: Implement**

In `McpHttpTransport`, after `validate()`:

```php
    /**
     * Mirror check for tool parameters annotated x-mcp-header (2026-07-28). The
     * header and the argument must agree: a gateway may have routed on the one
     * while this server acts on the other. Legacy requests carry no such headers.
     *
     * @param  array<string, mixed>   $request
     * @param  array<string, string>  $headers       lowercase header name => value
     * @param  array<string, string>  $paramHeaders  property => header name (ToolRegistry::paramHeaders())
     *
     * @throws McpProtocolError
     */
    public static function validateParamHeaders(array $request, array $headers, array $paramHeaders): void
    {
        if (($request['method'] ?? null) !== 'tools/call'
            || !array_key_exists('id', $request)
            || !McpProtocol::isModernRequest($request)
        ) {
            return;
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach ($paramHeaders as $property => $name) {
            $header = $headers['mcp-param-' . strtolower($name)] ?? null;
            $value = $arguments[$property] ?? null;

            if ($value === null) {
                if ($header !== null) {
                    throw self::mismatch("Mcp-Param-{$name} header sent, but params.arguments.{$property} is absent");
                }
                continue;
            }

            if ($header === null) {
                throw self::mismatch("Missing Mcp-Param-{$name} header for params.arguments.{$property}");
            }

            if (!self::sameParamValue($value, self::decodeHeaderValue("Mcp-Param-{$name}", $header))) {
                throw self::mismatch("Header mismatch: Mcp-Param-{$name} header does not match params.arguments.{$property}");
            }
        }
    }

    /**
     * Numbers compare numerically, so "5", "05" and "5.0" all name argument 5.
     */
    private static function sameParamValue(mixed $value, string $header): bool
    {
        if (is_bool($value)) {
            return $header === ($value ? 'true' : 'false');
        }

        if ((is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && is_numeric($header)) {
            return (float) $value === (float) $header;
        }

        return is_scalar($value) && (string) $value === $header;
    }
```

In `RpcHandlerTrait::handle()`, inside the existing `try` that calls
`McpHttpTransport::validate($request, $mcpHeaders);`, add the second call on the next line:

```php
            McpHttpTransport::validateParamHeaders($request, $mcpHeaders, $this->paramHeadersFor($request));
```

Add the method next to `extractToolName()`:

```php
    /**
     * @return array<string, string>  the called tool's x-mcp-header map
     */
    private function paramHeadersFor(array $request): array
    {
        $name = $this->extractToolName($request);
        if (($request['method'] ?? '') !== 'tools/call' || $name === '') {
            return [];
        }

        return ($this->resolveService(ToolRegistry::class) ?? new ToolRegistry())->paramHeaders($name);
    }
```

- [ ] **Step 4: Run the tests**

Run: `admin/vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: all tests pass.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/McpHttpTransport.php admin/src/Controller/RpcHandlerTrait.php tests/Unit/McpHttpTransportTest.php tests/Unit/RpcHandlerTransportTest.php
git commit -m "feat: validate Mcp-Param headers against tool arguments"
```

---

### Task 6: Progress primitives

**Files:**
- Create:
  - `admin/src/Service/ProgressSink.php`
  - `admin/src/Service/ProgressReporter.php`
  - `admin/src/Service/ProgressCancelled.php`
  - `admin/src/Service/HttpProgressSink.php`
- Create: `tests/Unit/RecordingProgressSink.php` (test double; not a test case)
- Test: `tests/Unit/ProgressReporterTest.php`, `tests/Unit/HttpProgressSinkTest.php`

**Interfaces:**
- Produces:
  - `interface ProgressSink { public function send(array $notification): void; public function isAborted(): bool; }`
  - `ProgressReporter::__construct(string|int $token, ProgressSink $sink, \Closure $clock, float $minIntervalSeconds = 0.25)`
  - `ProgressReporter::report(float $progress, ?float $total = null, ?string $message = null): void`,
    which throws `ProgressCancelled`.
  - `final class ProgressCancelled extends \RuntimeException`
  - `HttpProgressSink::__construct(\Closure $start, \Closure $write, \Closure $aborted)`, plus
    `hasStarted(): bool` and `finish(array $response): void`.
  - Test double: `Tests\Unit\RecordingProgressSink` with public `$sent` and a constructor
    `(bool $abortAfterFirst = false)`.

- [ ] **Step 1: Write the test double and failing tests**

`tests/Unit/RecordingProgressSink.php`:

```php
<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ProgressSink;

/**
 * Records notifications; optionally reports the client gone after the first.
 */
final class RecordingProgressSink implements ProgressSink
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function __construct(private readonly bool $abortAfterFirst = false)
    {
    }

    public function send(array $notification): void
    {
        $this->sent[] = $notification;
    }

    public function isAborted(): bool
    {
        return $this->abortAfterFirst && $this->sent !== [];
    }
}
```

`tests/Unit/ProgressReporterTest.php`:

```php
<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ProgressCancelled;
use Joomla\Component\Mcpserver\Administrator\Service\ProgressReporter;
use PHPUnit\Framework\TestCase;

class ProgressReporterTest extends TestCase
{
    private float $now = 100.0;

    public function testSendsAProgressNotificationWithTheToken(): void
    {
        $sink = new RecordingProgressSink();
        $this->reporter($sink)->report(1, 4, 'Fetched page 1 of 4');

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 'tok', 'progress' => 1.0, 'total' => 4.0, 'message' => 'Fetched page 1 of 4'],
        ]], $sink->sent);
    }

    public function testThrottlesWithinTheInterval(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(1, 10);
        $this->now += 0.1;
        $reporter->report(2, 10);
        $this->now += 0.3;
        $reporter->report(3, 10);

        $this->assertSame([1.0, 3.0], array_column(array_column($sink->sent, 'params'), 'progress'));
    }

    public function testTheFinalNotificationIsNeverThrottled(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(1, 2);
        $reporter->report(2, 2);

        $this->assertCount(2, $sink->sent);
    }

    public function testProgressNeverGoesBackwards(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(5);
        $this->now += 1;
        $reporter->report(5);
        $this->now += 1;
        $reporter->report(3);

        $this->assertCount(1, $sink->sent);
    }

    public function testOptionalFieldsAreOmitted(): void
    {
        $sink = new RecordingProgressSink();
        $this->reporter($sink)->report(1);

        $this->assertSame(['progressToken' => 'tok', 'progress' => 1.0], $sink->sent[0]['params']);
    }

    public function testADisconnectedClientCancelsTheWork(): void
    {
        $this->expectException(ProgressCancelled::class);

        $this->reporter(new RecordingProgressSink(abortAfterFirst: true))->report(1, 3);
    }

    private function reporter(RecordingProgressSink $sink): ProgressReporter
    {
        return new ProgressReporter('tok', $sink, fn (): float => $this->now);
    }
}
```

`tests/Unit/HttpProgressSinkTest.php`:

```php
<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink;
use PHPUnit\Framework\TestCase;

class HttpProgressSinkTest extends TestCase
{
    private int $starts = 0;

    private string $written = '';

    private bool $aborted = false;

    public function testStartsTheStreamOnceAndWritesFrames(): void
    {
        $sink = $this->sink();

        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 1, 'progress' => 1]]);
        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 1, 'progress' => 2]]);
        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame(1, $this->starts);
        $this->assertSame(3, substr_count($this->written, "event: message\ndata: "));
        $this->assertStringEndsWith("\"id\":1,\"result\":[]}\n\n", $this->written);
        $this->assertTrue($sink->hasStarted());
    }

    public function testNothingIsWrittenAfterTheClientLeft(): void
    {
        $sink = $this->sink();
        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []]);
        $this->aborted = true;

        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame(1, substr_count($this->written, 'event: message'));
        $this->assertTrue($sink->isAborted());
    }

    public function testAnUnstartedSinkWritesNothingAndIsNeverAborted(): void
    {
        $sink = $this->sink();
        $this->aborted = true;

        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame('', $this->written);
        $this->assertFalse($sink->isAborted());
        $this->assertFalse($sink->hasStarted());
    }

    private function sink(): HttpProgressSink
    {
        return new HttpProgressSink(
            function (): void { $this->starts++; },
            function (string $bytes): void { $this->written .= $bytes; },
            fn (): bool => $this->aborted
        );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run:

```bash
admin/vendor/bin/phpunit tests/Unit/ProgressReporterTest.php tests/Unit/HttpProgressSinkTest.php
```

Expected: errors because the classes and the interface are not found.

- [ ] **Step 3: Implement** (each file gets the project header, the namespace
  `Joomla\Component\Mcpserver\Administrator\Service` and `defined('_JEXEC') or die;`)

`ProgressSink.php`:

```php
/**
 * Where a request's progress notifications go while the request is still running.
 */
interface ProgressSink
{
    /**
     * Deliver one JSON-RPC notification now.
     *
     * @param  array<string, mixed>  $notification
     */
    public function send(array $notification): void;

    /**
     * Whether the client has gone away since the last send.
     */
    public function isAborted(): bool;
}
```

`ProgressCancelled.php`:

```php
/**
 * The client closed the request's stream. On Streamable HTTP that is the
 * cancellation signal: the work stops and nothing more is sent for it.
 */
final class ProgressCancelled extends \RuntimeException
{
}
```

`ProgressReporter.php`:

```php
/**
 * Turns a tool's progress points into notifications/progress for one request:
 * strictly increasing, throttled, and a point at which a departed client stops
 * the work.
 */
final class ProgressReporter
{
    private ?float $last = null;

    private ?float $lastSentAt = null;

    /**
     * @param  \Closure(): float  $clock  seconds
     */
    public function __construct(
        private readonly string|int $token,
        private readonly ProgressSink $sink,
        private readonly \Closure $clock,
        private readonly float $minIntervalSeconds = 0.25
    ) {
    }

    /**
     * @throws ProgressCancelled when the client has disconnected
     */
    public function report(float $progress, ?float $total = null, ?string $message = null): void
    {
        // The spec requires progress to increase with every notification.
        if ($this->last !== null && $progress <= $this->last) {
            return;
        }

        $now = ($this->clock)();
        $final = $total !== null && $progress >= $total;
        if (!$final && $this->lastSentAt !== null && $now - $this->lastSentAt < $this->minIntervalSeconds) {
            return;
        }

        $params = ['progressToken' => $this->token, 'progress' => $progress];
        if ($total !== null) {
            $params['total'] = $total;
        }
        if ($message !== null) {
            $params['message'] = $message;
        }

        $this->sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => $params]);
        $this->last = $progress;
        $this->lastSentAt = $now;

        // Only a write reveals a disconnect, so this is where the work can stop.
        if ($this->sink->isAborted()) {
            throw new ProgressCancelled('The client closed the request');
        }
    }
}
```

`HttpProgressSink.php`:

```php
/**
 * Streams progress as Server-Sent Events, starting the stream only when the
 * first notification arrives — a call that reports nothing stays a plain JSON
 * response. The closures keep header()/echo/flush() out of this class.
 */
final class HttpProgressSink implements ProgressSink
{
    private bool $started = false;

    /**
     * @param  \Closure(): void        $start    sends the event-stream status and headers
     * @param  \Closure(string): void  $write    writes and flushes bytes
     * @param  \Closure(): bool        $aborted  whether the client has disconnected
     */
    public function __construct(
        private readonly \Closure $start,
        private readonly \Closure $write,
        private readonly \Closure $aborted
    ) {
    }

    public function send(array $notification): void
    {
        if (!$this->started) {
            ($this->start)();
            $this->started = true;
        }

        ($this->write)(McpHttpTransport::sseFrames([$notification]));
    }

    public function isAborted(): bool
    {
        return $this->started && ($this->aborted)();
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * End the stream with the response — unless the client already left, in
     * which case nothing more may be sent for the request.
     *
     * @param  array<string, mixed>  $response
     */
    public function finish(array $response): void
    {
        if ($this->started && !($this->aborted)()) {
            ($this->write)(McpHttpTransport::sseFrames([$response]));
        }
    }
}
```

- [ ] **Step 4: Run them**

Run:

```bash
admin/vendor/bin/phpunit tests/Unit/ProgressReporterTest.php tests/Unit/HttpProgressSinkTest.php
```

Expected: OK (9 tests).

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/ProgressSink.php admin/src/Service/ProgressReporter.php admin/src/Service/ProgressCancelled.php admin/src/Service/HttpProgressSink.php tests/Unit/RecordingProgressSink.php tests/Unit/ProgressReporterTest.php tests/Unit/HttpProgressSinkTest.php
git commit -m "feat: add progress reporter and streaming sink"
```

---

### Task 7: Progress in `RpcService`: hooks, `fetchAllPages`, install stages, cancellation

**Files:**
- Modify: `admin/src/Service/RpcService.php`:
  - properties
  - `setProgressSink()`, `reportProgress()`, `progressReporterFor()`
  - `handleCallTool()`, `fetchAllPages()`, `installExtension()`
- Create: `tests/Unit/RpcServiceProgressTest.php`

**Interfaces:**
- Consumes: `ProgressReporter`, `ProgressSink`, `ProgressCancelled` (Task 6).
- Produces: `RpcService::setProgressSink(?ProgressSink $sink, ?\Closure $clock = null): void`,
  public. A cancelled call returns `JsonRpc` error `-32603` "Request cancelled by the client",
  with `getLastHttpStatus() === 499` and `wasLastCallFailed() === true`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/RpcServiceProgressTest.php`:

```php
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
    private function pagedRest(int $pages): RestClient
    {
        $rest = $this->createMock(RestClient::class);
        $rest->method('get')->willReturnCallback(function (string $path, array $query = []) use ($pages): array {
            $this->gets++;
            $page = intdiv((int) ($query['page[offset]'] ?? 0), 100);
            if ($page >= $pages) {
                return ['data' => [], 'meta' => ['total-pages' => $pages]];
            }

            return [
                'data' => array_map(
                    static fn (int $n): array => ['type' => 'modules', 'id' => $page * 100 + $n, 'attributes' => ['module' => 'mod_custom']],
                    range(1, 100)
                ),
                'meta' => ['total-pages' => $pages],
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
```

> Before relying on `list_custom_modules`, read `listCustomModules()` in `RpcService` (it calls
> `fetchAllPages()` and filters `mod_custom`). If its schema requires an argument, add it to the
> `callTool()` arguments here. The disconnect test counts API calls in the fake (`$this->gets`)
> rather than stacking `expects()` on a mock that already has a return callback: PHPUnit would
> return null from the second matcher.

- [ ] **Step 2: Run them to verify they fail**

Run: `admin/vendor/bin/phpunit tests/Unit/RpcServiceProgressTest.php`

Expected: an `undefined method RpcService::setProgressSink()` error.

- [ ] **Step 3: Implement**

In `RpcService`, add these properties after `$streamNotifications`:

```php
    private ?ProgressSink $progressSink = null;

    /** @var \Closure(): float|null */
    private ?\Closure $progressClock = null;

    /** The current tools/call's reporter, when it asked for progress. */
    private ?ProgressReporter $progress = null;
```

Add these methods after `takeStreamNotifications()`:

```php
    /**
     * Where progress for the next tools/call goes; null turns progress off. The
     * clock is injectable so tests can freeze the throttle.
     *
     * @param  \Closure(): float|null  $clock
     */
    public function setProgressSink(?ProgressSink $sink, ?\Closure $clock = null): void
    {
        $this->progressSink = $sink;
        $this->progressClock = $clock;
    }

    /**
     * A progress point for the running tool. Never call this after a mutation
     * has been committed: a departed client makes it throw ProgressCancelled,
     * which would report a completed change as cancelled.
     *
     * @throws ProgressCancelled
     */
    private function reportProgress(float $progress, ?float $total = null, ?string $message = null): void
    {
        $this->progress?->report($progress, $total, $message);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function progressReporterFor(array $params): ?ProgressReporter
    {
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $token = $meta['progressToken'] ?? null;
        if ($this->progressSink === null || !(is_string($token) || is_int($token))) {
            return null;
        }

        return new ProgressReporter(
            $token,
            $this->progressSink,
            $this->progressClock ?? static fn (): float => microtime(true)
        );
    }
```

In `handleCallTool()`, replace the executor `try { ... } catch (\Throwable $e) { ... }` block. Its
`catch (\Throwable $e)` body stays exactly as it is today:

```php
        $this->progress = $this->progressReporterFor($params);

        try {
            $result = $this->toolRegistry->execute($toolName, $toolParams);

            return JsonRpc::successResponse($id, $this->formatToolSuccess($result, $modern));
        } catch (ProgressCancelled) {
            // The client closed the stream (the Streamable HTTP cancellation
            // signal). Nothing more is sent, but the call is still audited, as a
            // failure under the client-closed-request status.
            $this->lastCallFailed = true;
            $this->lastHttpStatus = 499;

            return JsonRpc::errorResponse($id, JsonRpc::INTERNAL_ERROR, 'Request cancelled by the client');
        } catch (\Throwable $e) {
            // ... unchanged existing body ...
        } finally {
            $this->progress = null;
        }
```

In `fetchAllPages()`, report after each page is merged. The resulting method:

```php
    private function fetchAllPages(string $path, array $query = [], int $pageSize = 100, int $maxPages = 50): array
    {
        $items = [];
        $offset = 0;
        $totalPages = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->rest->get($path, array_merge($query, [
                'page[limit]'  => $pageSize,
                'page[offset]' => $offset,
            ]));

            if ($page === 0 && is_numeric($response['meta']['total-pages'] ?? null)) {
                $totalPages = min($maxPages, (int) $response['meta']['total-pages']);
            }

            $data = $response['data'] ?? [];
            if (!is_array($data) || $data === [] || isset($data['id'])) {
                break;
            }

            $items = array_merge($items, array_values($data));

            // Every caller fetches before it writes, so stopping here never
            // abandons a half-done change.
            $this->reportProgress(
                $page + 1,
                $totalPages !== null ? (float) $totalPages : null,
                'Fetched page ' . ($page + 1) . ($totalPages !== null ? ' of ' . $totalPages : '')
            );

            $count = count($data);
            $offset += $count;

            if ($count < $pageSize) {
                break;
            }
        }

        return $items;
    }
```

In `installExtension()`, add two progress points, and none after the install itself:

1. Directly after `$bytes = $this->resolveExtensionPackageBytes($params);`:

```php
        // Progress points stop at the install itself: a cancellation after it
        // would report an installed extension as cancelled.
        $this->reportProgress(1, 3, 'Package received');
```

2. Directly before `$installer = \Joomla\CMS\Installer\Installer::getInstance();`:

```php
            $this->reportProgress(2, 3, 'Package unpacked, installing');
```

- [ ] **Step 4: Run the tests**

Run: `admin/vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: all tests pass.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Service/RpcService.php tests/Unit/RpcServiceProgressTest.php
git commit -m "feat: report tool progress and stop cancelled calls cooperatively"
```

---

### Task 8: Stream progress from the HTTP handler

**Files:**
- Modify: `admin/src/Controller/RpcHandlerTrait.php`:
  - `handle()` dispatch section
  - new `createProgressSink()`
  - import `HttpProgressSink`
- Modify: `tests/Unit/RpcHandlerTransportTest.php`

**Interfaces:**
- Consumes: `HttpProgressSink` (Task 6) and `RpcService::setProgressSink()` (Task 7).
- Produces: none for later tasks.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/RpcHandlerTransportTest.php`:

```php
    public function testTheHandlerBuildsAnUnstartedHttpSink(): void
    {
        $sink = $this->invoke('createProgressSink');

        $this->assertInstanceOf(\Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink::class, $sink);
        $this->assertFalse($sink->hasStarted());
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `admin/vendor/bin/phpunit tests/Unit/RpcHandlerTransportTest.php`

Expected: an error that the method `createProgressSink` does not exist.

- [ ] **Step 3: Implement**

Add `use Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink;` to the trait's
imports.

In `handle()`, replace these two lines:

```php
        $rpcService = $this->rpcServiceFor($params, $principal);

        [$response, $dispatchFailed] = $this->dispatchToService($rpcService, $request, $method);
```

with:

```php
        $rpcService = $this->rpcServiceFor($params, $principal);

        // The legacy ?sessionId relay parks one complete response in a cache, so
        // it cannot carry a live stream.
        $progressSink = empty($sessionId) ? $this->createProgressSink() : null;
        $rpcService->setProgressSink($progressSink);

        [$response, $dispatchFailed] = $this->dispatchToService($rpcService, $request, $method);

        // The container's shared RpcService outlives this request.
        $rpcService->setProgressSink(null);
```

Change the `$httpStatus` line so a started stream records what was actually sent:

```php
        // Settled before the audit write so the row records what is sent. Once
        // progress has streamed the status was 200, or 499 if the client left.
        $httpStatus = match (true) {
            $progressSink?->hasStarted() === true => $rpcService->getLastHttpStatus() ?? 200,
            $dispatchFailed => 500,
            default => McpHttpTransport::responseStatus($rpcService->getLastHttpStatus(), $response),
        };
```

Directly after the `recordGovernanceAudit(...)` call that follows it, and before
`$jsonResponse = json_encode(...)`, insert:

```php
        if ($progressSink?->hasStarted() === true) {
            // The response joins the progress already streamed, unless the client left.
            $progressSink->finish($response);
            $app->close();
            return;
        }
```

Add the method next to `dispatchToService()`:

```php
    /**
     * The live progress stream for one request. The stream starts only when a
     * tool first reports progress; until then the response is ordinary JSON.
     */
    private function createProgressSink(): HttpProgressSink
    {
        return new HttpProgressSink(
            static function (): void {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                http_response_code(200);
                // Compression and output buffers would hold every event until the
                // end; a disconnect must not end the script before it is audited.
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                ignore_user_abort(true);
            },
            static function (string $bytes): void {
                echo $bytes;
                flush();
            },
            static fn (): bool => connection_aborted() === 1
        );
    }
```

- [ ] **Step 4: Run the tests and lint**

Run:

```bash
admin/vendor/bin/phpunit --configuration phpunit.xml.dist && php -l admin/src/Controller/RpcHandlerTrait.php
```

Expected: all tests pass; no syntax errors. The behaviour itself is verified end to end in
Task 12.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add admin/src/Controller/RpcHandlerTrait.php tests/Unit/RpcHandlerTransportTest.php
git commit -m "feat: stream tool progress over SSE from the HTTP handler"
```

---

### Task 9: Bridge relays event streams incrementally

**Files:**
- Modify: `site/mcp-http-bridge.js` (`post()`, `handleInput()`)
- Modify: `tests/bridge/mcp-http-bridge.test.js`

**Interfaces:**
- Produces: `post(message, flight, onNotification = null)` inside `createBridge`.
  - When `onNotification` is given, every notification is passed to it as it arrives, and the
    promise resolves with the *response-shaped* messages only (those with an `id` key).
  - Without it, the promise resolves with all messages.

- [ ] **Step 1: Write the failing test**

Append to `tests/bridge/mcp-http-bridge.test.js`:

```js
test('progress notifications are relayed while the stream is still open', async () => {
    let release;
    const released = new Promise((resolve) => { release = resolve; });

    await withBridge(
        async ({ body }, res) => {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            res.write('event: message\ndata: ' + JSON.stringify({
                jsonrpc: '2.0', method: 'notifications/progress', params: { progressToken: 't', progress: 1, total: 2 },
            }) + '\n\n');
            await released;
            res.end('event: message\ndata: ' + JSON.stringify({ jsonrpc: '2.0', id: body.id, result: { resultType: 'complete', content: [] } }) + '\n\n');
        },
        async (bridge, output) => {
            const pending = bridge.handleInput(JSON.stringify({
                jsonrpc: '2.0', id: 4, method: 'tools/call',
                params: { name: 'seo_audit_articles', arguments: {}, _meta: { ...MODERN_META, progressToken: 't' } },
            }));

            const deadline = Date.now() + 2000;
            while (output.length === 0 && Date.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 5));
            }
            assert.equal(output.length, 1, 'the progress notification must arrive before the stream ends');
            assert.equal(output[0].method, 'notifications/progress');

            release();
            await pending;
            assert.equal(output[1].id, 4);
        }
    );
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test tests/bridge/*.test.js`

Expected: the new test fails with `the progress notification must arrive before the stream ends`.
Today the bridge buffers the whole body.

- [ ] **Step 3: Implement**

In `createBridge`, change `post`'s signature to `function post(message, flight, onNotification = null)`
and replace the `client.request(options, (res) => { ... })` callback body with:

```js
            const req = client.request(options, (res) => {
                res.setEncoding('utf8');
                const streaming = String(res.headers['content-type'] || '').startsWith('text/event-stream');
                const messages = [];
                let data = '';
                let pending = '';
                let failure = null;

                // Each complete event is handled as it arrives: progress must reach
                // the client live, not when the stream ends.
                const deliver = (event) => {
                    for (const parsed of parseSseMessages(event)) {
                        messages.push(parsed);
                        if (onNotification && !('id' in parsed)) {
                            onNotification(parsed);
                        }
                    }
                };

                res.on('data', (chunk) => {
                    if (!streaming) {
                        data += chunk;
                        return;
                    }
                    pending += chunk;
                    const events = pending.split(/\r?\n\r?\n/);
                    pending = events.pop();
                    try {
                        events.forEach(deliver);
                    } catch (error) {
                        failure = failure || error;
                    }
                });

                res.on('end', () => {
                    if (streaming) {
                        try {
                            if (pending.trim() !== '') {
                                deliver(pending);
                            }
                        } catch (error) {
                            failure = failure || error;
                        }
                        if (failure) {
                            reject(new Error(`Invalid event stream (HTTP ${res.statusCode}): ${failure.message}`));
                            return;
                        }
                        // Notifications already went out; hand back what remains.
                        resolve(onNotification ? messages.filter((m) => 'id' in m) : messages);
                        return;
                    }
                    if ((res.statusCode === 202 || res.statusCode === 204) && data.trim() === '') {
                        resolve([]);
                        return;
                    }
                    if (res.statusCode >= 400 && !data.trim().startsWith('{')) {
                        reject(new Error(`HTTP Error ${res.statusCode}: ${data.slice(0, 100)}`));
                        return;
                    }
                    try {
                        const response = JSON.parse(data);
                        if (response && response.id === null && message.id !== undefined && message.id !== null) {
                            response.id = message.id;
                        }
                        resolve([response]);
                    } catch (error) {
                        reject(new Error(`Invalid JSON response (HTTP ${res.statusCode}): ${error.message}`));
                    }
                });
            });
```

In `handleInput`, replace the `const responses = ...` assignment with:

```js
            // Notifications (progress, subscription acks) are written the moment
            // they arrive; responses are written below, after any retry decision.
            const relayNotification = (notification) => {
                if (!flight || !flight.cancelled) {
                    write(notification);
                }
            };
            let responses = itemsKey && !params?.cursor
                ? [await fetchAllListPages(message, itemsKey, flight)].filter(Boolean)
                : await post(message, flight, relayNotification);
```

- [ ] **Step 4: Run the bridge tests**

Run:

```bash
node --check site/mcp-http-bridge.js && node --test tests/bridge/*.test.js
```

Expected: all pass, including the existing SSE, missing-response and cancellation tests.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add site/mcp-http-bridge.js tests/bridge/mcp-http-bridge.test.js
git commit -m "feat: bridge relays event-stream messages as they arrive"
```

---

### Task 10: Bridge mirrors `Mcp-Param-*` headers, with one refresh-and-retry

**Files:**
- Modify: `site/mcp-http-bridge.js`:
  - new exported `paramHeadersFromTools()`
  - `buildMcpHeaders()` gains `paramHeaders`
  - `createBridge` cache, `remember()` and the retry in `handleInput`
- Modify: `tests/bridge/mcp-http-bridge.test.js`

**Interfaces:**
- Consumes: `post(message, flight, onNotification)` (Task 9); the server's `x-mcp-header`
  annotations (Task 4).
- Produces:
  - `paramHeadersFromTools(tools): Map<string, Array<[property, header]>>`
  - `buildMcpHeaders(message, negotiatedVersion, paramHeaders = new Map())`, exported.

- [ ] **Step 1: Write the failing tests**

At the top of the test file, extend the destructured `require` with `paramHeadersFromTools`. Then
append:

```js
const ANNOTATED_TOOLS = [
    { name: 'get_article_by_id', inputSchema: { type: 'object', properties: { id: { type: 'integer', 'x-mcp-header': 'Id' } } } },
    { name: 'search_articles', inputSchema: { type: 'object', properties: { search: { type: 'string' } } } },
];

test('annotations become a per-tool header map', () => {
    const map = paramHeadersFromTools(ANNOTATED_TOOLS);

    assert.deepEqual(map.get('get_article_by_id'), [['id', 'Id']]);
    assert.deepEqual(map.get('search_articles'), []);
});

test('tools/call mirrors annotated arguments into Mcp-Param headers', () => {
    const map = paramHeadersFromTools(ANNOTATED_TOOLS);
    const headers = (args) => buildMcpHeaders(
        { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_article_by_id', arguments: args, _meta: MODERN_META } },
        null,
        map
    );

    assert.equal(headers({ id: 5 })['Mcp-Param-Id'], '5');
    assert.equal(headers({})['Mcp-Param-Id'], undefined);
    assert.equal(headers({ id: null })['Mcp-Param-Id'], undefined);
});

test('param headers learned from a relayed tools/list are sent on later calls', async () => {
    await withBridge(
        ({ body }, res) => json(res, 200, body.method === 'tools/list'
            ? { jsonrpc: '2.0', id: body.id, result: { tools: ANNOTATED_TOOLS } }
            : { jsonrpc: '2.0', id: body.id, result: { content: [] } }),
        async (bridge, _output, received) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: { _meta: MODERN_META } }));
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'get_article_by_id', arguments: { id: 5 }, _meta: MODERN_META } }));

            assert.equal(received[1].headers['mcp-param-id'], '5');
        }
    );
});

test('an unknown schema is learned once on HeaderMismatch and the call retried', async () => {
    await withBridge(
        ({ body, headers }, res) => {
            if (body.method === 'tools/list') {
                json(res, 200, { jsonrpc: '2.0', id: body.id, result: { tools: ANNOTATED_TOOLS } });
            } else if (headers['mcp-param-id'] === undefined) {
                json(res, 400, { jsonrpc: '2.0', id: body.id, error: { code: -32020, message: 'Missing Mcp-Param-Id header' } });
            } else {
                json(res, 200, { jsonrpc: '2.0', id: body.id, result: { content: [], ok: true } });
            }
        },
        async (bridge, output, received) => {
            await bridge.handleInput(JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'tools/call', params: { name: 'get_article_by_id', arguments: { id: 5 }, _meta: MODERN_META } }));

            assert.deepEqual(received.map((entry) => entry.body.method), ['tools/call', 'tools/list', 'tools/call']);
            assert.equal(output.length, 1, 'only the retried call answers the client');
            assert.equal(output[0].id, 9, 'under the id the client used');
            assert.equal(output[0].result.ok, true);
        }
    );
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test tests/bridge/*.test.js`

Expected: `paramHeadersFromTools is not a function`, and the retry test fails.

- [ ] **Step 3: Implement**

Add after `buildMcpHeaders`:

```js
/**
 * Tool name → [[property, header]] from a tools/list result's x-mcp-header
 * annotations, so tools/call can mirror those arguments into Mcp-Param-* headers.
 */
function paramHeadersFromTools(tools) {
    const map = new Map();
    for (const tool of Array.isArray(tools) ? tools : []) {
        if (!tool || typeof tool.name !== 'string') {
            continue;
        }
        const properties = tool.inputSchema && typeof tool.inputSchema.properties === 'object' ? tool.inputSchema.properties : {};
        const pairs = [];
        for (const [property, schema] of Object.entries(properties || {})) {
            if (schema && typeof schema['x-mcp-header'] === 'string') {
                pairs.push([property, schema['x-mcp-header']]);
            }
        }
        map.set(tool.name, pairs);
    }

    return map;
}
```

Change `buildMcpHeaders(message, negotiatedVersion)` to
`buildMcpHeaders(message, negotiatedVersion, paramHeaders = new Map())`, and before
`return headers;` add:

```js
    if (message.method === 'tools/call' && typeof params.name === 'string') {
        const args = params.arguments && typeof params.arguments === 'object' ? params.arguments : {};
        for (const [property, header] of paramHeaders.get(params.name) || []) {
            const value = args[property];
            if (value !== undefined && value !== null) {
                headers[`Mcp-Param-${header}`] = encodeHeaderValue(String(value));
            }
        }
    }
```

Then change the following inside `createBridge`:

1. Add `const toolParamHeaders = new Map();` next to `const inFlight = new Map();`.
2. In `post`, change `...buildMcpHeaders(message, negotiatedVersion),` to
   `...buildMcpHeaders(message, negotiatedVersion, toolParamHeaders),`.
3. In `remember(message, response)`, after the `serverName` block, add:

```js
        if (Array.isArray(result.tools)) {
            for (const [name, pairs] of paramHeadersFromTools(result.tools)) {
                toolParamHeaders.set(name, pairs);
            }
        }
```

4. In `handleInput`, directly after the `let responses = ...` statement from Task 9, add:

```js
            // A client may call a tool before this bridge has relayed its schema;
            // its Mcp-Param-* headers were then missing. Learn the schema and retry
            // once, as the transport spec advises on HeaderMismatch.
            const mismatch = responses.some((r) => r && r.error && r.error.code === -32020);
            if (method === 'tools/call' && mismatch && !toolParamHeaders.has(params?.name)) {
                const listed = await fetchAllListPages(
                    { jsonrpc: '2.0', id: `${requestId}-tools`, method: 'tools/list', params: { _meta: params?._meta } },
                    'tools',
                    flight
                );
                remember({ method: 'tools/list' }, listed);
                if (toolParamHeaders.has(params?.name)) {
                    responses = (await post({ ...message, id: `${requestId}-retry` }, flight, relayNotification))
                        .map((r) => ('id' in r ? { ...r, id: requestId } : r));
                }
            }
```

5. Export the new function:
   `module.exports = { encodeHeaderValue, buildMcpHeaders, parseSseMessages, paramHeadersFromTools, createBridge };`.

- [ ] **Step 4: Run the bridge tests**

Run:

```bash
node --check site/mcp-http-bridge.js && node --test tests/bridge/*.test.js
```

Expected: all pass.

- [ ] **Step 5: Commit** (only if authorised)

```bash
git add site/mcp-http-bridge.js tests/bridge/mcp-http-bridge.test.js
git commit -m "feat: bridge mirrors Mcp-Param headers and retries once on an unknown schema"
```

---

### Task 11: Python client sends `Mcp-Param-*` headers

**Files:**
- Modify: `scripts/joomla_mcp_client.py`:
  - `JoomlaHttpClient.__init__`
  - `_headers()`
  - `list_tools()`
  - `call_tool()`

**Interfaces:**
- Consumes: the server's `x-mcp-header` annotations.
- Produces: `JoomlaHttpClient._param_headers: dict[str, dict[str, str]]`.

- [ ] **Step 1: Write the failing check**

Run:

```bash
python3 -c "
import sys; sys.path.insert(0, 'scripts')
from joomla_mcp_client import JoomlaHttpClient
c = JoomlaHttpClient('http://x')
c._remember_param_headers([{'name': 'get_article_by_id', 'inputSchema': {'properties': {'id': {'type': 'integer', 'x-mcp-header': 'Id'}}}}])
m = c._message('tools/call', {'name': 'get_article_by_id', 'arguments': {'id': 5}}, 1)
assert c._headers(m).get('Mcp-Param-Id') == '5', c._headers(m)
m = c._message('tools/call', {'name': 'get_article_by_id', 'arguments': {}}, 2)
assert 'Mcp-Param-Id' not in c._headers(m)
print('ok')"
```

Expected: `AttributeError: ... '_remember_param_headers'`.

- [ ] **Step 2: Implement**

1. In `JoomlaHttpClient.__init__`, add:

```python
        self._param_headers: dict[str, dict[str, str]] = {}
        self._param_headers_loaded = False
```

2. Add the method below `_headers()`:

```python
    def _remember_param_headers(self, tools: list[dict[str, Any]]) -> None:
        """Cache each tool's x-mcp-header annotations (argument -> header name)."""
        for tool in tools:
            properties = (tool.get("inputSchema") or {}).get("properties") or {}
            self._param_headers[tool.get("name", "")] = {
                prop: schema["x-mcp-header"]
                for prop, schema in properties.items()
                if isinstance(schema, dict) and isinstance(schema.get("x-mcp-header"), str)
            }
        self._param_headers_loaded = True
```

3. In `_headers()`, before `return headers`, add:

```python
        if message["method"] == "tools/call":
            arguments = params.get("arguments") or {}
            for prop, name in self._param_headers.get(params.get("name", ""), {}).items():
                value = arguments.get(prop)
                if value is not None:
                    text = str(value).lower() if isinstance(value, bool) else str(value)
                    headers[f"Mcp-Param-{name}"] = encode_header_value(text)
```

4. Replace `list_tools()` with:

```python
    def list_tools(self) -> list[dict[str, Any]]:
        tools = self._list_paginated("tools/list", "tools")
        self._remember_param_headers(tools)
        return tools
```

5. At the start of `call_tool()`, add:

```python
        # Modern servers reject a call missing its Mcp-Param-* headers, which
        # come from the tool schemas.
        if self.protocol_version and not self._param_headers_loaded:
            self.list_tools()
```

- [ ] **Step 3: Run the check again**

Run the command from Step 1, followed by:

```bash
python3 -m py_compile scripts/joomla_mcp_client.py
```

Expected: `ok`.

- [ ] **Step 4: Commit** (only if authorised)

```bash
git add scripts/joomla_mcp_client.py
git commit -m "feat: eval client mirrors Mcp-Param headers"
```

---

### Task 12: Documentation and end-to-end verification

**Files:**
- Modify:
  - `README.md` (Features list, MCP Prompts, Protocol Versions)
  - `CHANGELOG.md` (`## Unreleased`)
  - `CLAUDE.md` (untracked)
- Scratchpad only:
  - `<scratchpad>/e2e/router.php`
  - `<scratchpad>/e2e/checks-phase-a.sh`

  `<scratchpad>` is
  `/tmp/claude-1000/-home-allans-Projects-workspace-php-com-mcpserver/36a9c6ed-a543-4693-acd7-181c8b2389bf/scratchpad`.

- [ ] **Step 1: README**
  - **Features list:** add "Argument completion for prompts and article resources, live progress
    for long-running tools, and `Mcp-Param-*` headers for gateway routing".
  - **MCP Prompts section:** add a sentence. Clients that support completion get suggestions for
    `category`, `article_id` and `target_language`, drawn from the caller's own view of the site.
  - **Protocol Versions → For `2026-07-28` requests:** add a bullet. Tools whose schema marks
    `id`, `version_id`, `extension_id` or `catid` with `x-mcp-header` require the matching
    `Mcp-Param-Id`, `Mcp-Param-Version-Id`, `Mcp-Param-Extension-Id` or `Mcp-Param-Catid` header,
    and a missing or mismatched one gets `-32020`. The bundled bridge sends them.
  - **Protocol Versions → For every revision:** add a bullet. A `tools/call` with
    `_meta.progressToken` receives `notifications/progress` on an event-stream response while it
    runs: paged fetches, plus `install_extension`'s stages. Closing the stream cancels the call at
    its next progress point, and the call is logged with status 499. Also mention that
    `completion/complete` is available whenever prompts or resources are enabled.
  - **Not implemented paragraph:** remove "change notifications, completions" from the optional
    list, add "push change notifications (planned)", and keep the rest.

- [ ] **Step 2: CHANGELOG** — under `## Unreleased`, add top-level `- ` bullets only.
  `scripts/sync_changelog_entry.py` reads only those.
  - "Added argument completion (`completion/complete`)…" — what is completed, where the data
    comes from (the caller's token and ACL), and that the capability depends on Enable Prompts and
    Enable Resources.
  - "Added live progress notifications…" — which tools report progress, the event-stream switch,
    that cancellation is cooperative, and audit status 499.
  - "Added `x-mcp-header` annotations…" — the four headers, enforcement for `2026-07-28`
    requests only, and that the bridge mirrors and retries.
  - "Added a server icon, title, description and website URL to the server information, top-level
    tool titles, and `lastModified` on article resources."

- [ ] **Step 3: CLAUDE.md** — under "Executor conventions", add:
  > Progress: long-running executors call `reportProgress()` at safe points. It may throw
  > `ProgressCancelled` when the client has gone, so never call it after a mutation has been
  > committed. `fetchAllPages()` reports per page; every caller fetches before it writes.

  Under "Protocol eras", add one line: `x-mcp-header` annotations come from
  `ToolRegistry::PARAM_HEADERS` and are checked by `McpHttpTransport::validateParamHeaders()`.

- [ ] **Step 4: Extend the e2e harness fake API with paging and delay**

In `<scratchpad>/e2e/router.php`, add before the existing article routes:

```php
// Slow, paged module list: three full pages, 400 ms each, so progress is visible.
if (str_starts_with($uri, '/api/index.php/v1/modules/site')) {
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $offset = (int) ($q['page']['offset'] ?? 0);
    usleep(400000);
    header('Content-Type: application/vnd.api+json');
    $rows = $offset >= 300 ? [] : array_map(
        static fn (int $n): array => ['type' => 'modules', 'id' => $offset + $n, 'attributes' => ['module' => 'mod_custom', 'title' => 'M' . ($offset + $n)]],
        range(1, 100)
    );
    echo json_encode(['data' => $rows, 'meta' => ['total-pages' => 3]]);
    return true;
}
```

If `listCustomModules()` uses a different path, match the `$path` it passes to `fetchAllPages()`.

- [ ] **Step 5: Run the e2e checks**

1. Start the harness twice: once normally on port 8765, once with
   `-d output_buffering=4096 -d zlib.output_compression=1` on port 8766 (Review Focus 1).
   Use `PHP_CLI_SERVER_WORKERS=4 php -S ... router.php` as in memory `e2e-harness-php-s`.
2. Write `<scratchpad>/e2e/checks-phase-a.sh` to assert the following:
   - `server/discover` serverInfo has `title` and an `icons[0].src` ending
     `components/com_mcpserver/icon.png`.
   - `completion/complete` for `draft-article`/`category` returns 200 with `completion.values`.
   - On both ports, a modern `tools/call` `list_custom_modules` with `_meta.progressToken` and
     `curl -N` shows `notifications/progress` lines arriving about 400 ms apart, timestamped with
     `ts` or `awk '{ print strftime("%T"), $0 }'`, before the final response frame.
   - The same call with `curl --max-time 0.6` disconnects. The audit JSONL then gains a
     `tools/call` row with `status: error` and `http_status: 499`, and the server log shows no
     PHP warnings.
   - A modern `tools/call get_article_by_id {"id":5}` with `Mcp-Param-Id: 5` returns 200; with
     `Mcp-Param-Id: 6` it returns 400 `-32020`; without the header it returns 400 `-32020`.
   - The new bridge, fed a modern `tools/call get_article_by_id` on stdin with no prior
     `tools/list`, returns a successful result (refresh and retry), and the harness log shows a
     `tools/list` request between two `tools/call` requests.
   - The bridge relays progress lines for the slow `list_custom_modules` call before its final
     result (pipe through `ts`).
3. Run the checks.

Expected: every check passes. Stop the harness by port, never with `pkill -f`.

- [ ] **Step 6: Full verification**

Run:

```bash
admin/vendor/bin/phpunit --configuration phpunit.xml.dist
find . -path ./admin/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
node --check site/mcp-http-bridge.js && node --test tests/bridge/*.test.js
python3 -m py_compile scripts/*.py
docker run --rm -v "$PWD":/app -w /app php:8.1-cli sh -c 'admin/vendor/bin/phpunit --configuration phpunit.xml.dist 2>&1 | tail -3'
```

Expected: all green. On PHP 8.1 the only failure is the known `McpbServiceTest` one, because the
image lacks `ext-zip`.

- [ ] **Step 7: Commit** (only if authorised)

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document completions, progress and Mcp-Param headers"
```
