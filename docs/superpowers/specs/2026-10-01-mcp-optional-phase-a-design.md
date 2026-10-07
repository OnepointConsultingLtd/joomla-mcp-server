# MCP optional features — Phase A design

Date: 2026-10-01 · Status: approved in conversation, awaiting spec review

## Context and intent

The component now serves MCP `2026-07-28` alongside the legacy revisions. The user asked for every
optional part of the specification. We agreed to deliver it in five phases, each with its own
spec, plan and build cycle:

| Phase | Covers |
|---|---|
| **A** (this spec) | metadata, completions, `x-mcp-header`, progress |
| B | elicitation through multi-round-trip input requests |
| C | push change notifications |
| D | the tasks extension |
| E | OAuth 2.1 |

Logging, sampling and roots are excluded: `2026-07-28` deprecates them, and the specification
says new implementations SHOULD NOT adopt them.

Success means:

- clients see the new capabilities and metadata;
- every new rule is enforced and unit-tested;
- legacy clients and installed bridges keep working;
- existing security properties hold: fail-closed defaults, no internal details in errors, ACL
  applied to data reads.

## A1 — Metadata (all protocol versions)

- **Tool titles.** `ToolRegistry::register()` copies `annotations.title` into a top-level
  `title` when the tool has none. Display precedence is `title`, then `annotations.title`, then
  `name`.
- **Server identity in `initialize` and `server/discover`.** The `serverInfo` object gains:
  - `title: "MCP Server for Joomla"`
  - `description`: one sentence describing the server
  - `websiteUrl: "https://github.com/OnepointConsultingLtd/joomla-mcp-server"`
  - `icons: [{src, mimeType: "image/png", sizes: ["64x64"]}]`

  `src` is `Uri::root() . 'components/com_mcpserver/icon.png'`.
- **Per-result `serverInfo`.** The copy that `McpProtocol::completeResult()` puts into every
  modern result's `_meta` stays `{name, version}`, so ordinary responses stay small.
- **Icon file.** `site/icon.png` is a 64×64 PNG generated once from `assets/logo.png` and
  committed. It is added to the `<files folder="site">` list in `mcpserver.xml`.
- **Resource dates.** Each article resource from `resources/list` gains
  `annotations.lastModified`, taken from the article's `modified` attribute when present and
  converted to ISO 8601.

## A2 — Completions (both eras)

**Capability.** `completions: {}` is advertised in `initialize` and `server/discover` when prompts
or resources are enabled.

**Errors.**

- Neither feature enabled: `completion/complete` returns `-32601`.
- Malformed `ref` or `argument`: `-32602`.

**Routing.** `completion/complete` lives in `dispatchCommon()`. Modern results get `resultType`
through `completeResult()`; they are not cacheable. The method accepts two kinds of reference:

- **`ref/prompt`.** The prompt must exist and prompts must be enabled; otherwise `-32602` naming
  the prompts that exist.
- **`ref/resource`.** The `uri` must be `joomla://article/{id}` and resources must be enabled;
  otherwise `-32602`.

**Argument sources.**

| Reference | Argument | Source |
|---|---|---|
| `draft-article` | `category` | Titles of published content categories |
| `seo-audit-article`, `translate-article` | `article_id` | Articles: a numeric value matches IDs by prefix; text is a title search, and the values returned are the matching IDs |
| `joomla://article/{id}` | `id` | Same as `article_id` |
| `translate-article` | `target_language` | `lang_code` of published content languages |
| `draft-article` | `topic` | Free text: always an empty list |

- An argument the reference does not define gets `-32602` listing the arguments it does define.

**Matching.**

- Matching is case-insensitive.
- Prefix matches come first, then substring matches, de-duplicated.
- At most 100 values are returned, with `total` set to the number of matches and `hasMore` true
  when more than 100 matched.

**Access control.** All data comes through the existing executors (`listCategories`,
`searchArticles`, `listContentLanguages`). They use the caller's own `RestClient` and
`CacheService`, so Joomla's ACL and the per-principal cache apply unchanged. Request frequency is
bounded by the existing per-IP rate limiter.

**Failures.** A failing fetch is reported through `clientErrorMessage()` as `-32603`.

## A3 — `x-mcp-header` (`Mcp-Param-*`)

- **Annotation.** `ToolRegistry::register()` annotates each top-level `integer` property named in
  the table below. That covers 48 tools today; all of these properties are integers.

  | Property | Header name |
  |---|---|
  | `id` | `Id` |
  | `version_id` | `Version-Id` |
  | `extension_id` | `Extension-Id` |
  | `catid` | `Catid` |

  A property of any other type is never annotated.
- **Schema.** `x-mcp-header` is an annotation keyword. `justinrainbow/json-schema` ignores it, so
  `ToolSchemaDialectTest` allows it.
- **Which requests are validated.** Only modern `tools/call` requests, in a new
  `McpHttpTransport::validateParamHeaders(array $request, array $headers, ?array $tool)`. It runs
  after `validate()`. An unknown tool skips the check, because dispatch then answers `-32602`.
  Legacy requests are never checked.
- **What each annotated property requires.**
  - **Value present and non-null in `arguments`.** The `mcp-param-{name}` header must be present
    and valid: visible ASCII, with any base64 sentinel decoded. It must equal the value, compared
    as integers.
  - **Value absent or null.** A header for that property is a mismatch.
  - Any failure is `-32020` with HTTP 400 and a message naming the header.
  - Headers no annotation covers are ignored, as the spec requires.
- **Bridge.** It caches each tool's `{property → header}` map from every `tools/list` response it
  relays, and mirrors the headers onto `tools/call`, encoded with `encodeHeaderValue`. If it has
  no cached schema for the tool and the server answers `-32020`, it fetches `tools/list` once
  (every page, internally), updates the cache and retries the call once with a new id.
- **Python client.** `JoomlaHttpClient` caches the maps from `list_tools()` and sends the headers.
- **Audit.** A rejection is audited `invalid_request` with the method and tool, through the
  existing `rejectProtocolError()`.

## A4 — Progress (both eras; not the legacy `?sessionId` relay)

**Trigger.** A `tools/call` whose `params._meta.progressToken` is a string or integer.

**Who reports.**

- **Contract.** `RpcService` exposes a `ProgressReporter` to executors through a private
  `reportProgress(float $progress, ?float $total, ?string $message)`. When no token or no sink is
  present it does nothing.
- **Pages.** `fetchAllPages()` reports "page n of m" (`total` from the API's `meta.total-pages`
  when available).
- **Installs.** `installExtension()` reports its download, unpack and install stages.

**Delivery** (`ProgressSink`, set by the trait, lazy streaming):

- The trait passes a sink into `RpcService` before dispatch.
- On the first notification the sink:
  - sends HTTP 200 with `text/event-stream`, `Cache-Control: no-cache` and
    `X-Accel-Buffering: no`;
  - calls `ignore_user_abort(true)` so a disconnect cannot kill the script before it is audited;
  - writes the frame and flushes.
- Each later notification is one SSE frame, flushed.
- The final response is the stream's last frame. If no notification was ever sent the response is
  the usual JSON with the usual status.
- Once the stream has started, a later error travels as a JSON-RPC error in the stream with HTTP
  200, which the spec allows.

**Notification shape and pacing.**

- Each notification is `{progressToken, progress, total?, message?}`.
- `progress` strictly increases.
- Notifications are sent at most once every 250 ms. A notification with `progress >= total`, or
  the last page of a walk whose page count is unknown, is always sent. A tool that cannot safely
  report after its last mutation (`install_extension`) ends below total.

**Cancellation.**

- After each flush the sink checks `connection_aborted()`. If the client has gone, it throws a
  `ProgressCancelled` exception.
- `handleCallTool()` catches it, sets `lastCallFailed`, and returns a response that is never sent.
  The sink suppresses all further output.
- The request is still audited, as `error`, with HTTP status 499 recorded internally.
- Work stops at the next progress point — cooperative, so a single long upstream call still runs
  to its end.

**Bridge.** The bridge parses the event stream incrementally, writing each complete message to
stdout as it arrives instead of buffering the whole body. Cancellation semantics are unchanged.

## Components touched

| Area | Change |
|---|---|
| `ToolRegistry` | title copy, `x-mcp-header` annotation |
| `RpcService` | serverInfo extras, completions, progress reporter hooks, cancellation catch |
| `McpHttpTransport` | `validateParamHeaders()`, SSE frame helper reuse |
| `RpcHandlerTrait` | progress sink wiring and lazy SSE start, param-header validation |
| `site/` | `icon.png` |
| `mcpserver.xml` | site file list |
| `site/mcp-http-bridge.js` | param-header mirroring and retry, incremental SSE |
| `scripts/joomla_mcp_client.py` | param headers |
| Docs | README (Protocol Versions, Completions, progress notes), CHANGELOG `## Unreleased`, CLAUDE.md |

New classes, in `admin/src/Service/`:

- `CompletionService`: pure matching and limiting, fed by closures; unit-testable.
- `ProgressReporter`: throttling, monotonicity, token.
- `ProgressCancelled` (exception).
- The sink is an interface or closure defined beside `ProgressReporter`. The trait implements it
  with `header()`, `echo` and `flush()`.

## Testing

- `CompletionServiceTest`:
  - prefix before substring;
  - case-insensitivity;
  - the 100 cap with `total` and `hasMore`;
  - empty results.
- `RpcServiceCompletionsTest`:
  - each reference and argument;
  - unknown prompt, argument or URI give `-32602`;
  - the capability depends on policy;
  - modern results carry `resultType`;
  - ACL: the executor is called through the caller's `RestClient` mock.
- `ToolRegistry` and dialect tests:
  - `title` is copied;
  - the 48 annotations land on integer properties only;
  - header names are unique per schema;
  - `x-mcp-header` is allowed.
- `McpHttpTransportTest`: param-header matrix:
  - header present and matching;
  - header missing while the value is present;
  - header present while the value is absent;
  - mismatch;
  - integers compared numerically (`"5"` vs `5`);
  - base64 sentinel;
  - invalid characters;
  - legacy requests exempt;
  - unknown tool skipped.
- `ProgressReporterTest`:
  - throttle;
  - monotonicity;
  - final notification always sent;
  - no token means no notifications;
  - cancellation throws once the sink reports an abort.
- `RpcServiceProgressTest`:
  - `fetchAllPages` emits page progress;
  - cancellation sets `lastCallFailed` and stops paging.
- Bridge (`node:test`):
  - `Mcp-Param-*` mirroring from a cached `tools/list`;
  - refresh and single retry on `-32020`;
  - incremental SSE (progress written before the stream ends).
- End-to-end harness (scratchpad):
  - a slow paged fake API showing live progress through `curl -N` and the bridge;
  - cancellation via client disconnect, with the audit row checked;
  - completions;
  - param-header acceptance and rejection;
  - the icon URL.

## Out of scope for Phase A

- Elicitation (B).
- Push notifications (C).
- Tasks (D).
- OAuth (E).
- Per-tool icons, which the user declined.
- `outputSchema` for tools.
- Deprecated logging, sampling and roots.
