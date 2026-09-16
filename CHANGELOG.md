# Changelog

All notable release changes for MCP Server for Joomla are recorded here.

## 1.9.0 - 2026-09-15

- Added 15 custom field tools covering the full lifecycle of `com_fields`: `list_fields`, `get_field`, `find_field_by_name`, `create_field`, `update_field`, `delete_field` and `reorder_fields` for field definitions; `list_field_groups`, `get_field_group`, `create_field_group`, `update_field_group`, `delete_field_group` and `reorder_field_groups` for the tabs fields are organised into; and `get_item_field_values` / `set_item_field_values` for the values stored on an individual article, category, contact or user. Creating or configuring a field previously meant a manual round-trip through the administrator, and resolving a field's ID from its technical name meant querying `#__fields` with raw SQL.
- Added `get_extension_params` and `update_extension_params`, which read and write the Options an administrator edits on an extension's Options screen (`#__extensions.params`) — a plugin's settings, a component's Options, a module type's or template's defaults. Joomla's Web Services API silently ignores `params` on an extension write, so configuring a freshly deployed plugin previously meant a round-trip through the backend for every change. Identify the extension by `extension_id` or by `element` plus `type`, adding `folder` for a plugin and `client` where the same element is installed for both site and administrator.
- `update_field` and `update_field_group` merge `params` and `fieldparams` into the existing values rather than replacing them, so only the keys you send change. Joomla's own PATCH handler backfills table columns but hands whatever it receives straight to `FieldTable::bind`, which replaces the whole column — a partial `params` object sent directly to the Web Services API silently discards every other key.
- `update_field` and `reorder_fields` always resend the field's category assignments. `assigned_cat_ids` is not a column in `#__fields` — it lives in `#__fields_categories` — so Joomla's PATCH backfill never restores it, and `FieldModel::save()` deletes every assignment when it is absent. Reordering a field would otherwise have detached it from its categories.
- Read tools report a field's selectable options as a separate stored `value` and displayed `label`, and `get_item_field_values` reports each value as both `raw_value` and `display_value`. A `list`, `radio` or `checkboxes` field stores only the option value; the label lives in `fieldparams.options` and can be renamed in the administrator, so conflating the two loses information. A value whose option has since been removed displays as it is stored rather than vanishing.
- `set_item_field_values` rejects a field name that does not exist in the context and names the valid ones. Joomla drops unrecognised keys without complaining, which would otherwise read back as a successful no-op.
- All 15 field tools are **disabled by default** and must be enabled individually in the Disabled Tools option. Unlike the existing code-execution tools, these are gated because they change the site's content schema rather than its content, and deleting a field destroys every value stored against it.
- The field tools accept the six field contexts core Joomla declares (`com_content.article`, `com_content.categories`, `com_contact.contact`, `com_contact.mail`, `com_contact.categories`, `com_users.user`) and reject anything else by name. A 404 from a field collection reports which of `plg_webservices_content`, `_contact` or `_users` to enable, since core registers the `com_fields` routes from those plugins rather than from one of its own. Field values are not writable for `com_content.categories` (Joomla's category API controller does not map them) or `com_contact.mail` (the contact form stores no items), and both are rejected explicitly.
- Writing a field value, or changing a field definition, now clears the cached article reads for that context. Custom field values are embedded in an article's own API representation — for single reads and for lists alike — so `get_article_by_id` or `search_articles` could otherwise return a pre-update value for up to the cache TTL.
- Per-field and per-group permission rules are not exposed by Joomla's Web Services API, so the field tools neither read nor write them; the view access level (`access`) is fully supported, and updates never touch a field's existing rules.
- `update_extension_params` merges into the stored params rather than replacing them, matching `update_module` and the field tools: only the keys you send change, a `null` value removes a key, and a nested object is replaced wholesale rather than deep-merged. An extension's params column holds every option it has, so a write that meant to change one setting would otherwise reset the rest.
- `get_extension_params` also returns `option_definitions` — the options the extension's own manifest declares (name, type, label language key, default, list options), read from `config.xml` for a component and from the installation manifest for every other type. An extension whose Options have never been saved stores an empty params object even though its manifest declares defaults, so an empty `params` is not evidence that it has no options; the definitions are how the key names are discovered. Keys the manifest does not declare are still written and reported back in `unknown_keys`, since extensions legitimately store options they never declare.
- Values of manifest fields typed `password` are masked as `********` on read and listed in `redacted_keys`, including when `include_definitions` is false — extensions keep real credentials in their Options, and the merge semantics mean a masked value never has to be sent back to preserve it.
- Both extension params tools refuse this component's own options, for reading and for writing: they hold the MCP bearer token and the Joomla API token, and they carry the read-only, Disabled Tools and authentication settings that gate the tool doing the asking.
- Both extension params tools are **disabled by default** and require site-wide `core.admin` in Governed Mode, matching Joomla's own gating of Options screens. An extension's Options are where third-party extensions keep their secrets, so reading them is a disclosure risk and writing them reconfigures the site.
- Changing a plugin's params now clears Joomla's `com_plugins` cache group, so the new settings take effect on the next request instead of after the cache expires.

## 1.8.0 - 2026-09-12

- Added Governed Mode: per-user issued, revocable credentials that authenticate MCP requests individually and make outbound Joomla API calls with each user's own API token instead of the single shared token, with a `#__mcpserver_credential` table storing encrypted tokens keyed on a generated credential salt. Governed Mode is opt-in and disabled by default; the existing shared-token workflow is unchanged.
- Added a credential request workflow, with the approval queue flagging any request whose owner cannot create a Joomla API token (the User - Joomla API Token plugin permits only Super Users by default) so it is caught before approval rather than at a failed claim: eligible users request access, a different Super User approves or rejects it with a per-request expiry (or grants a credential that never expires), and the owner claims an approved request with their own validated Joomla API token. Backed by the `#__mcpserver_credential_request` and `#__mcpserver_request_event` tables.
- Replaced direct credential issuance with an approval workflow: eligible users request access, a different Super User approves or rejects it with a per-request expiry, and the owner claims approved requests with their own validated Joomla API token.
- Added pending request review and all-credential metadata administration queues while preserving owner-only credential listings for regular users.
- Documented the governed credential request, approval, and claim workflow.
- Added Governed Mode: per-client issued, revocable credentials that authenticate MCP requests individually and make outbound Joomla API calls with each user's own API token instead of the single shared token, with a `#__mcpserver_credential` table storing encrypted tokens keyed on a generated credential salt.
- Added a governance audit trail (extended `#__mcpserver_request_log` columns: `request_id`, `credential_id`, `user_id`, `credential_selector`, `target`) and attribution of successful mutating tool calls made under a governed credential to Joomla's core Action Logs, when the `System - Action Logs` plugin is enabled.
- Restricted the Dashboard to the viewer's own request log: only a Super User sees the whole site's requests. Every figure on the page — the summary cards, the requests-per-day chart, the top tool and method tables and the Recent Requests list — is filtered to the acting user's Joomla account, so scoping the table alone cannot leak other users' activity in aggregate. A restricted viewer is not offered the audit User ID filter, and the page says which rows it excluded so an empty table is never mistaken for an idle site.
- Changed the Dashboard's audit **Tool Name** filter from free text to a dropdown built from the tools and prompts the running build registers, so a typo can no longer look like "this tool was never called". The filtered column records a prompt name for `prompts/get` as well as a tool name for `tools/call`, so both are offered, grouped by kind. Entries switched off in Options are listed separately but stay selectable, since disabling one does not delete the rows it already produced; a filter naming something this version does not register — a `resources/read` URI, or a tool removed by an upgrade — is kept as an option too, so the dropdown always shows the filter actually in force.
- Replaced the Dashboard audit trail's raw **User ID** column with the account name, and turned the Super User **User** filter into a dropdown of the accounts that actually appear in the log (rather than every account on the site, most of which would match nothing). The id is still shown where it is the only identifier left: a row whose account has since been deleted from Joomla reads `Deleted user (#42)` rather than a nameless "Unavailable", and a row with no account at all — legacy shared-token mode, or a failure before authentication — reads "Not attributed", which is a different fact and no longer renders identically.
- Added a **Manage Credentials** administrator page for requesting and claiming credentials, reviewing pending requests, administering all-credential metadata while preserving owner-only listings for regular users, and viewing the recovery key fingerprint. The credential salt that keys credential encryption is generated automatically on install and update; the page offers a manual fallback only when that has not happened, and refuses to mint a replacement salt while credentials that it could not decrypt already exist.
- Added local Joomla ACL enforcement for governed requests to tools that bypass the Web Services API, so a governed credential can never exceed its owner's own permissions.
- Reorganised the administrator navigation: the component submenu is now **Client Configuration** and **Dashboard**, Options is the toolbar button on every view (previously a submenu entry of the same name that opened the landing page instead), and **Manage Credentials** is reached from the Governed Mode panel on Client Configuration, which appears only while Governed Mode is on, with a Back button in its toolbar since it has no submenu entry of its own.
- Documented Governed Mode setup, the credential request/approval/claim workflow, migrating clients off the shared bearer/API token, rollback and recovery, and the Joomla Action Logs prerequisite in the README.
- Added menu (page) assignment to `update_module`: `assignment` (`0` = all pages, `1` = only the selected pages, `-1` = all pages except the selected pages, `"-"` = no pages) together with `assigned`, the list of menu item IDs. Setting which pages a module shows on previously had to be done by hand in the administrator — the fields were rejected with "No updatable fields supplied" — which left a manual step in an otherwise scripted site build. Omitting both leaves the module's current assignment untouched, and `params` is still merged into the module's existing settings rather than replaced wholesale as Joomla's own API does. Combinations Joomla silently reinterprets (an empty `assigned` with `assignment` `1` or `-1`) and menu item IDs that do not exist are reported as errors instead of quietly assigning the module to the wrong pages.
- Fixed **Metrics Retention (days)**, **Rate Limit Requests** and **Rate Limit Window** rendering in Options as empty dropdowns with nothing to select, so their values could not be changed at all. All three were declared as Joomla `integer` fields, which build a select list from `first`/`last`/`step` and ignore the `min`/`max` they were given — with no `step`, that list is empty and the declared default never applies. They are now number inputs with explicit ranges. Metrics Retention had been unsettable since 1.1.0 and the rate-limit options since 1.0.0. No data was pruned unexpectedly in the meantime: Joomla's registry falls back to the default for an empty stored value, so the built-in defaults (360 days, 60 requests per 60 seconds) were in force throughout.

## 1.7.1 - 2026-09-07

- `update_template_file` now creates a file when it does not exist, provided its parent directory already does (for example a second layout in an existing override folder). The response reports `created`.
- A missing parent directory is now reported as "Directory does not exist" rather than implying a path-traversal attempt.
- Added a backup warning on the component landing page, reminding administrators to take a full backup and test with Read-Only Mode before connecting a write-capable LLM client.

## 1.7.0 - 2026-08-13

- Added four read-only site-diagnostics tools (`get_rendered_page`, `diff_article_versions`, `seo_audit_articles`, `check_internal_links`) so an agent can verify what a visitor sees, what changed between article versions, and whether content has SEO or internal-link problems.
- Added MCP Resources (recent articles as `joomla://article/{id}`) and MCP Prompts (`draft-article`, `seo-audit-article`, `translate-article`), with Enable Resources / Enable Prompts options.
- Added a PHPUnit harness that constructs `RpcService` (schema/executor pairing) and unit-tests Joomla API payload-normalisation quirks: article content aliases (`articletext`/`text`/`content` → `introtext`), search filters as `filter[...]` query parameters, and complete merged menu-item PATCH payloads.
- Fixed `clear_cache` wiping the protected `mcp_sse` and `com_mcpserver_ratelimit` groups when they were passed as an explicit `group`.
- Fixed `clear_cache` dispatching `AfterPurgeEvent` with an empty `subject` when clearing all groups, which TypeErrors in Joomla 5.
- Fixed Claude Desktop bundle generation silently using an empty archive path when `tempnam()` fails.

## 1.6.0 - 2026-08-02

- Fixed every MCP tool failing with an inscrutable HTML `404` on hosts where `/api/` is not routed to Joomla's API application (stock nginx vhosts): the component now detects a non-JSON API response and returns an actionable error explaining the request never reached the API application, with the required nginx `location /api` and Apache `.htaccess`/mod_rewrite guidance.
- Documented an `/api/` reachability check (with a copy-pasteable `curl` command, a response-format diagnostic table and the required nginx block) as a verification step in the API token configuration.
- Added a Claude Desktop extension bundle (`com_mcpserver.mcpb`) to every release: a ~10 KB zip of the zero-dependency HTTP bridge whose manifest supplies its own Node runtime and collects the endpoint URL and bearer token through a settings UI, so client setup no longer requires Node.js, npm or hand-edited JSON.
- Added a Download Claude Desktop extension button to the MCP Client Configuration card that generates a personalised bundle on the fly: the endpoint URL is pre-filled and the connector is named after the site, so managing several Joomla sites yields clearly distinguishable connectors. The bearer token is never embedded in the downloaded file.
- The RPC Endpoint shown in the MCP Client Configuration card now honours the configured Base URL, matching the endpoint baked into the generated Claude Desktop bundle.
- Fixed the README's MCP client configuration snippet failing on Windows with Claude Desktop. The bundled HTTP bridge is now the only documented configuration — the `npx mcp-remote` snippet has been removed — with a Windows note requiring the absolute `node.exe` path, since any `cmd.exe` layer (`npx`, `npx.cmd`, `cmd /c`) splits the endpoint URL on `&`, producing a misleading HTML 404.

## 1.5.0 - 2026-07-21

- Added a `clear_cache` tool that clears Joomla's system cache so recent changes become visible on the site; clears every cache group by default or a single named group (e.g. `page`, `com_content`), while always preserving the MCP server's own session and rate-limit caches.
- Updated the Disabled Tools option help text.
- Updated the CI and release workflows to `actions/checkout@v5` and Node.js 24.

## 1.4.2 - 2026-07-08

- Fixed a PHP parse error in the packaged `vendor/composer/ClassLoader.php` (line 531).
- The build now runs `php -l` over every packaged PHP file after the JED-prep rewrites, so a corrupted vendor file fails the build.
- Increased bearer token field size for longer token support.

## 1.4.1 - 2026-07-06

- Fixed the Disabled Tools setting not updating when cleared: Joomla treats an empty saved value as unset and re-applies the default list, so the field now accepts `none` to explicitly allow all registered tools.
- Tool calls denied by server policy (a disabled tool or Read-Only Mode) are now recorded in request metrics with a `blocked` status and shown on the dashboard, instead of being logged as `ok`.

## 1.4.0 - 2026-07-05

- Added category tools: `list_categories`, `get_category`, `create_category`, `update_category`, `delete_category`.
- Added tag tools: `list_tags`, `get_tag`, `create_tag`, `update_tag`, `delete_tag`.
- Added extension management tools: `list_extensions`, `set_extension_state` (enable/disable, e.g. activating a plugin after install) and `uninstall_extension`.
- Added `create_menu`, `delete_menu_item`, `create_module` (any installed module type) and `delete_module` for full menu/module CRUD.
- Added a Read-Only Mode option that blocks every tool not annotated read-only.
- Fixed `search_articles` filters (`search`, `catid`, `state`, `author`, `language`) being silently ignored — they are now sent as the `filter[...]` query parameters the Joomla API reads; `author` is now a numeric user ID and a `featured` filter was added.
- Fixed `delete_article` failing on non-trashed articles; it now trashes first, then deletes (same behaviour for the new category, tag and menu item delete tools).
- Fixed pagination on `list_modules`, `list_menus`, `list_template_styles` and `list_content_languages`: they now accept `limit`/`offset` and forward them to the Joomla API, so items beyond the API's first page (20) are reachable.
- Fixed `list_custom_modules` hiding custom modules beyond the first 20 modules; it now aggregates every API page before filtering.
- Fixed rate limiting and the SSE response relay silently not working when Joomla's global caching is off (the Joomla default); the component now forces caching on for its own cache instances.
- Fixed `update_custom_module` writing without checking the module exists, matches the requested client and is a `mod_custom` module.
- Added JSON-RPC batch request support (required by MCP protocol revision 2025-03-26).
- CORS preflight now allows the `Mcp-Session-Id` and `MCP-Protocol-Version` headers sent by Streamable HTTP MCP clients.
- Security: `install_extension`, `uninstall_extension` and `update_template_file` (the code-execution tools) are now disabled by default; remove them from Disabled Tools in the options to opt in. `update_template_file` now carries an arbitrary-code-execution warning.
- Removed the unused Default Language option.

## 1.3.5 - 2026-06-30

- HTTP stdio bridge now aggregates paginated `tools/list` (and other list) responses so clients that ignore `nextCursor` still receive every tool.
- Added a configurable **Tools List Page Size** option (default 100) for `tools/list` pagination.
- Fixed Tools List Page Size using a number input instead of an empty integer dropdown.
- Fixed Cache TTL using a number input instead of an empty integer dropdown.
- Added server `instructions` on initialise describing list-tool pagination and `nextCursor` behaviour.
- Clarified `limit`/`offset` parameter descriptions on paginated list tools.

## 1.3.4 - 2026-06-30

- Fixed update feed infourl entries pointing to the wrong release tag.
- Added garbage collection and session cleanup to JoomlaCache to prevent stale SSE session data accumulating.
- Enhanced update.xml entries with maintainer, PHP minimum, and platform metadata.
- Updated documentation.

## 1.3.3 - 2026-06-29

- Fixed tools listing pagination.
- Updated `install_extension` tool description to prefer source path over base64 content.

## 1.3.2 - 2026-06-23

- Added SHA-256 checksum verification for release packages: the release workflow now records the package hash in `update.xml` so Joomla can validate downloaded updates.
- Fixed paging issues with broken offset value as well as a wrong total_count.
- Added evaluations for paging.

## 1.3.1 - 2026-06-22

- Improved tool execution failures and policy denials handling.
- Added pagination metadata (`total_count`, `count`, `offset`, `has_more`, `next_offset`) to all list tool responses.
- Added a **Disabled Tools** option to block specific MCP tools by name in component security settings.
- Fixed **Resolve Host To IP** so the override also applies to server local downloads.
- Added an MCP evaluation suite.
- Updated documentation.

## 1.3.0 - 2026-06-20

- Added a **Resolve Host To IP** option: pin the Base URL hostname to a specific IP (e.g. `127.0.0.1`) for the component's outbound REST calls only. Lets a server reach its own public hostname when NAT hairpinning is blocked, while keeping the correct Host header and TLS validation intact.

## 1.2.1 - 2026-06-20

- Updated project branding: added a new logo and refreshed dashboard screenshots, and removed the outdated overview screenshot.
- Added a GitHub star banner to the dashboard.
- Added more detail for the API token configuration in the README.

## 1.2.0 - 2026-06-20

- Added **extension installation** support: a new `install_extension` MCP tool installs extensions onto the site.
- Added **template editing** MCP tools: list installed templates, list and read template files, update template files, and create template overrides.
- Fixed the client configuration snippet generation in the component dashboard view.
- Fixed the Monolog log file path resolution.
- Reordered the administrator menu items.
- Updated the build script to also update the update.xml file.

## 1.1.0 - 2026-06-02

- Added request metrics: every MCP request is recorded to a new `#__mcpserver_request_log` table (method, tool, status, error code, HTTP status, latency, client IP, context).
- Added an admin **Dashboard** view with summary cards (total / last 24h / last 7d / error rate / average latency / rate-limit hits / auth failures), a requests-per-day chart, top tools and methods, and a recent-requests log.
- Added **Monitoring & Metrics** options: toggle recording on/off and configure the retention window (old rows are pruned automatically).

## 1.0.0 - 2026-05-31

- First stable release published to the Joomla Extensions Directory under `GPL-2.0-or-later`.
- Aligned the administrator menu and headings with the listing name "MCP Server for Joomla".
- Hardened the release build for the official JED Checker.

## 0.6.0 - 2026-05-28

- Added template style and installed template MCP tools.
- Added article and menu item multilingual association tools.
- Added article content history tools for listing, reading, keeping, deleting and restoring versions.
- Prepared the project for Joomla Extensions Directory release under `GPL-2.0-or-later`.
- Added Joomla update server metadata for GitHub release packages.
