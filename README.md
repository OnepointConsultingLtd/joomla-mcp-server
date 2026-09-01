# MCP Server for Joomla

A Joomla 4, 5 and 6 component that exposes a [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server over HTTP JSON-RPC. It lets MCP clients such as Claude Desktop and Cursor work with Joomla content through the site's own Joomla Web Services API.

**Version:** 1.8.0 · **Requires:** Joomla 4, 5 or 6 · PHP 8.1+ · **Licence:** GPL-2.0-or-later

## Features

- Administrator dashboard with request summary (totals, error rate and auth failures), a requests-per-day chart, top tools and methods, and a requests log
- One-click Claude Desktop extension (`.mcpb`), generated on demand from the administrator or attached to every release
- Security with bearer token authentication, optional IP allow-listing and CORS origin control
- Configurable fixed-window rate limiting
- Response caching through Joomla's cache layer
- JSON Schema validation for MCP tool inputs
- Health endpoint for monitoring
- MCP Resources (recent published articles as `joomla://article/{id}`) and guided Prompts (draft, SEO audit, translate)
- Joomla update server metadata for official releases

## MCP Tools

The component exposes 71 tools grouped by Joomla domain. List tools include a `pagination` object (`total_count`, `count`, `offset`, `has_more`, `next_offset`) so agents can page through large result sets. Write tools use Joomla's Web Services API where possible; a small number of behaviours not exposed cleanly through Web Services (custom module HTML writes, multilingual associations, template file editing) are handled through Joomla's database or filesystem APIs.

### Articles

| Tool | Description |
|---|---|
| `get_article_by_id` | Retrieve a Joomla article by ID |
| `search_articles` | Search Joomla articles |
| `create_article` | Create a new Joomla article |
| `update_article` | Update an existing Joomla article |
| `delete_article` | Delete a Joomla article (trashes it first when needed, then deletes permanently) |

### Categories

| Tool | Description |
|---|---|
| `list_categories` | List Joomla content categories (use to discover valid `catid` values) |
| `get_category` | Retrieve a Joomla content category by ID |
| `create_category` | Create a new Joomla content category |
| `update_category` | Update an existing Joomla content category |
| `delete_category` | Delete a Joomla content category (trashes first, then deletes; the category must be empty) |

### Tags

| Tool | Description |
|---|---|
| `list_tags` | List Joomla tags |
| `get_tag` | Retrieve a Joomla tag by ID |
| `create_tag` | Create a new Joomla tag |
| `update_tag` | Update an existing Joomla tag |
| `delete_tag` | Delete a Joomla tag (trashes first, then deletes) |

### Article versions

| Tool | Description |
|---|---|
| `list_article_versions` | List saved versions (content history) for a Joomla article |
| `get_article_version` | Retrieve a single article version from content history |
| `diff_article_versions` | Compare two saved article versions (unified diff for introtext/fulltext) |
| `keep_article_version` | Toggle the "keep forever" flag on an article version |
| `delete_article_version` | Delete a single article version from content history |
| `restore_article_version` | Restore a Joomla article to a previous saved version |

Article versioning tools require Joomla article versioning to be enabled.

### Custom modules

| Tool | Description |
|---|---|
| `create_custom_module` | Create a new Joomla "Custom" (`mod_custom`) module |
| `list_custom_modules` | List all Joomla "Custom" (`mod_custom`) modules |
| `get_custom_module_by_id` | Retrieve a Joomla "Custom" module by ID |
| `update_custom_module` | Update the content of a Joomla "Custom" module |

### Modules

| Tool | Description |
|---|---|
| `list_modules` | List all Joomla modules |
| `get_module_by_id` | Retrieve a Joomla module by ID |
| `create_module` | Create a new module of any installed type (type-specific settings via `params`) |
| `update_module` | Update any Joomla module (all types); merges type-specific params |
| `delete_module` | Delete a Joomla module and its page assignments |

### Menus and menu items

| Tool | Description |
|---|---|
| `list_menus` | List all Joomla menus (menu types) |
| `create_menu` | Create a new Joomla menu (menu type) |
| `list_menu_items` | List menu items, optionally filtered by menu type |
| `get_menu_item` | Retrieve a Joomla menu item by ID |
| `create_menu_item` | Create a new Joomla menu item |
| `update_menu_item` | Update an existing Joomla menu item |
| `delete_menu_item` | Delete a Joomla menu item (trashes first, then deletes) |

### Media

| Tool | Description |
|---|---|
| `list_media` | List Joomla media files and folders |
| `get_media` | Retrieve a single Joomla media file or folder by path |
| `upload_media` | Upload a new Joomla media file |
| `create_media_folder` | Create a new folder in the Joomla media library |
| `update_media` | Rename, move or replace an existing media file or folder |
| `delete_media` | Delete a Joomla media file or folder by path |

### Content languages

| Tool | Description |
|---|---|
| `list_content_languages` | List Joomla content languages (tags assignable to articles, menu items, etc.) |
| `get_content_language` | Retrieve a Joomla content language by ID |
| `create_content_language` | Create a new Joomla content language |
| `update_content_language` | Update an existing Joomla content language |
| `delete_content_language` | Delete a Joomla content language by ID |

### Installed languages

| Tool | Description |
|---|---|
| `list_installed_languages` | List languages installed on the Joomla site (site and administrator clients) |

### Template styles

| Tool | Description |
|---|---|
| `list_template_styles` | List Joomla template styles for the chosen client |
| `get_template_style` | Retrieve a Joomla template style by ID |
| `create_template_style` | Create a new template style for an already-installed template |
| `update_template_style` | Update an existing Joomla template style |
| `delete_template_style` | Delete a Joomla template style |

### Installed templates

| Tool | Description |
|---|---|
| `list_installed_templates` | List templates installed on the Joomla site (site and administrator clients) |

### Template files

| Tool | Description |
|---|---|
| `list_template_files` | List editable source files of an installed template (Joomla's "Customise" view) |
| `get_template_file` | Read the source of a single template file |
| `update_template_file` | Overwrite the source of an existing template file |
| `create_template_override` | Create a template override by copying a core view, module, plugin or layout into the template |

### Extensions

| Tool | Description |
|---|---|
| `list_extensions` | List installed extensions (components, modules, plugins, templates, languages, …) |
| `set_extension_state` | Enable or disable an installed extension (e.g. activate a plugin after installing it) |
| `install_extension` | Install a Joomla extension from a base64 zip or a download URL (arbitrary code execution — restrict to trusted callers) |
| `uninstall_extension` | Uninstall an extension by `extension_id` (protected/locked core extensions are refused) |

`install_extension`, `uninstall_extension` and `update_template_file` are **disabled by default** because they allow code execution on the server. Remove them from the Disabled Tools list in the component options to opt in.

### Multilingual associations

| Tool | Description |
|---|---|
| `list_article_associations` | List cross-language associations for a Joomla article |
| `set_article_associations` | Set cross-language associations for a Joomla article |
| `list_menu_item_associations` | List cross-language associations for a Joomla site menu item |
| `set_menu_item_associations` | Set cross-language associations for a Joomla site menu item |

### Maintenance

| Tool | Description |
|---|---|
| `clear_cache` | Clear Joomla's system cache so recent changes become visible on the site (all groups, or a single group such as `page` or `com_content`; site, administrator or both clients) |

### Site diagnostics

| Tool | Description |
|---|---|
| `get_rendered_page` | Fetch the HTML a guest visitor sees for an article or menu item (anonymous request, 512 KB cap, 30 s timeout) |
| `seo_audit_articles` | Audit published articles for missing titles, missing/short/long metadesc, and duplicate aliases in the same category |
| `check_internal_links` | Resolve article hyperlinks offline against published/unpublished articles and menu paths; external links are never probed |

`get_rendered_page` fetches the public site as an anonymous visitor so the result matches what a guest actually sees after the template and content plugins run. `check_internal_links` never issues HTTP requests for external URLs. `seo_audit_articles` does not inspect `metakey` — Joomla stopped using keyword meta tags in 2009.

### Not covered (by design)

User management, Joomla global configuration, custom fields (com_fields), contacts, banners and redirects are deliberately not exposed as tools. User accounts and global configuration in particular would widen the blast radius of a leaked bearer token well beyond content management. If your workflow needs one of these domains, open an issue — they are candidates for opt-in tools in a future release.

## MCP Resources

When **Enable Resources** is on (the default), MCP clients can attach published articles as context without a tool call.

- `resources/list` returns up to 50 recent published articles, newest first, as `joomla://article/{id}`.
- `resources/templates/list` advertises the template `joomla://article/{id}`.
- `resources/read` returns the article HTML (`introtext` + `fulltext`, `mimeType` `text/html`).

Turning the option off omits the resources capability, returns empty lists, and answers `resources/read` with method-not-found.

## MCP Prompts

When **Enable Prompts** is on (the default), MCP clients can pick guided workflows from a menu:

| Prompt | Arguments | Purpose |
|---|---|---|
| `draft-article` | `topic` (required), `category` (optional) | Draft a new article matching the tone of recent published articles, for `create_article` |
| `seo-audit-article` | `article_id` (required) | Audit an article for SEO and suggest `update_article` changes |
| `translate-article` | `article_id` and `target_language` (required) | Translate an article, then `create_article` and `set_article_associations` |

Turning the option off omits the prompts capability, returns an empty list, and answers `prompts/get` with method-not-found.

## Installation

Download the latest `com_mcpserver-<version>.zip` package from the GitHub releases page, then install it in Joomla Administrator via **System → Install → Extensions**.

For a local development build:

```bash
./build.sh
```

The build creates `com_mcpserver-<version>.zip` at the repository root. The version is read from `mcpserver.xml`.

## Configuration

Open **Administrator → Components → MCP Server → Options**.

Key settings:

- `Server Name`: identifier returned in MCP server information.
- `Base URL`: base URL of the Joomla site. Leave empty to use the current site.
- `API Token`: Joomla Web Services API token used for outbound REST calls.
- `Verify SSL`: verifies SSL certificates for outbound requests.
- `Resolve Host To IP`: optional. Pins the Base URL hostname to a specific IP (e.g. `127.0.0.1`) for the component's outbound REST calls only. Use when the server cannot reach its own public hostname (NAT hairpinning); the Host header and TLS validation still use the real hostname, so `Verify SSL` can stay on.
- `Cache TTL`: response cache lifetime in seconds.
- `Require Auth`: requires MCP clients to send a bearer token.
- `MCP Bearer Token`: token clients must send in `Authorization: Bearer`.
- `IP Allow List`: comma-separated client IP allow list.
- `Allowed Origins`: comma-separated CORS origin allow list.
- `Trusted Proxies`: comma-separated proxy IPs trusted for `X-Forwarded-For`.
- `Read-Only Mode`: when enabled, only read-only tools may run; every tool that writes, deletes or installs anything is blocked.
- `Disabled Tools`: comma- or newline-separated MCP tool names to block (e.g. `delete_article`). Defaults to the code-execution tools (`install_extension`, `uninstall_extension`, `update_template_file`); remove them to opt in, or enter `none` to allow all tools (an emptied field reverts to the defaults when saved).
- `Enable Resources`: when enabled (the default), MCP clients can list and read recent published articles as `joomla://article/{id}` resources.
- `Enable Prompts`: when enabled (the default), MCP clients can use the draft, SEO audit and translate article prompts.
- `Rate Limit Requests` and `Rate Limit Window`: fixed-window rate limit settings.

### Configuring the API Token

The `API Token` setting holds a Joomla Web Services API token. The component uses it to make outbound REST calls to your site's own Joomla Web Services API, which is how most MCP tools read and write content. Without a valid token, those tools will fail.

1. **Enable the Web Services API.** In the Joomla administrator, go to **System → Global Configuration → Server** and ensure the Web Services components are available. The relevant plugins live under **System → Plugins**; enable **Web Services - Content** (and any other `Web Services -` plugins for the data you want to access). The API plugin **System - Joomla API Authentication** must also be enabled — it is by default.

2. **Create a token for a user.** Tokens are tied to a Joomla user account, and API calls run with that user's permissions, so use an account that has the access the MCP tools need (for full functionality, a Super User or an account with the equivalent component permissions).
   - Go to **Users → Manage**, edit the chosen user, and open the **Joomla API Token** tab.
   - Set **Token Enabled** to *Yes*, click **Save**, then copy the generated token. (If the tab is missing, enable the **User - Joomla API Token** plugin under **System → Plugins**.)

3. **Paste the token into the component options.** Back in **Components → MCP Server → Options**, paste the value into **API Token** and click **Save**.

4. **Verify the API is reachable.** The component calls your site's own API under `/api/`, so the web server must route that path to Joomla's API application. On Apache this works out of the box — Joomla's core `.htaccess` rewrites `/api/` requests to `api/index.php`. On nginx there is no equivalent by default, so every tool fails while `health.ping` still reports `ok` (that endpoint only checks the component itself, not the outbound API layer). Check with:

   ```bash
   curl -i "https://example.com/api/index.php/v1/content/articles?page[limit]=1" \
     -H "X-Joomla-Token: <API_TOKEN>" -H "Accept: application/vnd.api+json"
   ```

   The status code alone is not enough — the response format tells you where the problem lies:

   | Response from `/api/…` | Actual cause |
   |---|---|
   | HTML page | The request never reached the API application → web server routing (see the nginx note below) |
   | JSON `404` | The relevant `Web Services - *` plugin is disabled |
   | JSON `401`/`403` | The API token is invalid, or its user lacks sufficient permissions |

   On nginx, add this block **before** the general `location /` block, then run `nginx -t` and reload:

   ```nginx
   location /api {
       try_files $uri $uri/ /api/index.php$is_args$args;
   }
   ```

> **Security note:** treat the API token like a password. It grants the token user's level of access to your site. Store it only in trusted configuration, and regenerate it (by toggling **Token Enabled** off and on) if it may have been exposed.

## Governed Mode (per-client credentials)

By default the component authenticates every MCP client with the single shared **MCP Bearer Token** and makes outbound Joomla API calls with the **Legacy Shared API Token** in Basic Settings. **Governed Mode** ignores that Basic Settings token and replaces it with individually issued, revocable credentials: each MCP client authenticates with its own bearer token, and every request is made using the Joomla API token encrypted inside that client's credential. Successful mutating tool calls made under a governed credential are additionally attributed to the credential's Joomla user in both the component's own request log and, when available, Joomla's core **Action Logs**.

### Prerequisite: Joomla Action Logs

Governed Mode attributes successful mutating tool calls (create/update/delete-type calls, not read-only ones) to the issuing user in Joomla's core **System - Action Logs** plugin, in addition to the component's own audit trail. Before cutover, enable it under **System → Plugins → System - Action Logs**. If the plugin (or `com_actionlogs` itself) is not installed or enabled, the Action Log write is silently skipped — the MCP response and the component's own audit trail (`#__mcpserver_request_log`) are unaffected — so governed mode still functions, but per-user actions will not appear in **Users → Action Logs**. Enable it first if you need that attribution for compliance or review.

### Setup

1. Go to **Administrator → Components → MCP Server → My Credentials**. This requires `core.admin` on `com_mcpserver` (a Super User, or an account granted equivalent permission).
2. Click **Provision salt & enable governed mode**. This generates a random credential salt (stored in the component's own configuration, not in `mcpserver.xml` or any file) if one does not already exist, and enables the `Governed Mode` option. The credential salt, combined with the Joomla application secret, derives the key that encrypts every stored credential's underlying Joomla API token — back it up as part of your normal Joomla database backups; see Recovery below.
3. The resulting **recovery key fingerprint** (a one-way hash, never the salt or secret itself) is shown on the same page. Record it: after a database restore or migration, compare it against the fingerprint shown post-restore to confirm the credential salt was preserved intact, before assuming existing credentials will still decrypt.

### Migrating clients off the shared token

Governed Mode is a single site-wide toggle (`Governed Mode` in **Options → Security Settings**), not a per-client switch, so plan the cutover as: issue first, then flip the toggle.

1. With Governed Mode still **disabled** (clients keep working on the shared `MCP Bearer Token`), complete Setup above so the credential salt exists.
2. For each MCP client (or each user who should have their own), go to **My Credentials**, supply that user's own Joomla API token, choose an expiry, and click **Issue credential**. The one-time bearer token shown must be copied immediately — it is never displayed again — and configured in the client the same way the shared bearer token was (`Authorization: Bearer <token>`, or `HTTP_AUTH_BEARER` for the bundled bridge).
3. Once every client that must keep working has its own credential issued and configured, enable **Governed Mode** in **Options → Security Settings** (or via the **My Credentials** page if not already done in Setup).
4. From this point, the shared `API Token` and `MCP Bearer Token` settings are no longer consulted for MCP requests; each client authenticates and acts as its own issued credential and its own Joomla user.

### Rollback

Disabling **Governed Mode** in **Options → Security Settings** is the rollback: it does not delete the credential salt, any issued credential, or the audit trail — it only stops governed authentication being used, so requests fall back to the shared `MCP Bearer Token` and shared `API Token` immediately. Re-enabling later resumes governed authentication with the same salt and the same still-active credentials, without needing to re-run Setup or reissue credentials (unless they have since expired or been revoked).

### Recovery

- **Lost or revoked a credential:** issue a replacement from **My Credentials** for the same user; the old credential's bearer token cannot be recovered (it is never stored), only revoked.
- **Restoring the site from a database backup:** because encrypted credential tokens are keyed on the credential salt (`#__extensions.params.credential_salt` for `com_mcpserver`) together with the Joomla application secret, restore both from the same backup as the `#__mcpserver_credential` table. Compare the recovery key fingerprint shown on **My Credentials** before and after the restore to confirm the salt was preserved; a changed fingerprint means every existing credential must be reissued.
- **Joomla application secret rotated independently of a restore:** this also invalidates every existing credential's stored ciphertext, since the encryption key is derived from both the secret and the salt. Reissue credentials for every affected client after rotating the secret.

## Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/index.php?option=com_mcpserver&task=rpc.handle` | MCP JSON-RPC endpoint in the site application |
| `GET` | `/index.php?option=com_mcpserver&task=rpc.sse` | Server-Sent Events stream used by the stdio bridge |
| `GET` | `/index.php?option=com_mcpserver&task=health.ping` | Site health endpoint |
| `POST` | `/administrator/index.php?option=com_mcpserver&task=rpc.handle` | MCP JSON-RPC endpoint in the administrator application |
| `GET` | `/administrator/index.php?option=com_mcpserver&task=health.ping` | Administrator health endpoint |

## Claude Desktop Extension (.mcpb)

For Claude Desktop the easiest client setup is the bundled extension: a `.mcpb` file (a zip in the [MCPB](https://github.com/modelcontextprotocol/mcpb) format) that installs with a double-click. It contains the component's own zero-dependency HTTP bridge, so there is no Node.js install, no npm package and no hand-edited JSON — Claude Desktop supplies the Node runtime itself, and the connector appears as *MCP Server for Joomla* with the project logo.

**Download it from your own site (recommended).** In **Administrator → Components → MCP Server**, click **Download Claude Desktop extension** in the MCP Client Configuration card. The component generates a personalised bundle on the fly: the endpoint URL is pre-filled and the connector is named after the site, which keeps several Joomla sites clearly distinguishable in Claude Desktop.

**Or download the generic bundle.** Every GitHub release also publishes `com_mcpserver.mcpb` alongside the component zip.

To install:

1. Double-click the downloaded `.mcpb` file (requires Claude Desktop).
2. Enter the **MCP endpoint URL** — pre-filled when the bundle was downloaded from your site; otherwise copy the *RPC Endpoint* shown under **Components → MCP Server**.
3. Enter the **MCP Bearer Token** from **Components → MCP Server → Options**.

The bearer token is never embedded in the downloaded file: it remains a one-time paste into Claude Desktop's settings, so no live credential lands in your downloads folder, backups or sync folders.

## Desktop Client Bridge

**Claude Desktop users:** prefer the [.mcpb extension](#claude-desktop-extension-mcpb) above — it needs no Node.js or manual configuration. The bridge below remains for other stdio clients and custom setups.

For MCP clients that use stdio transport, run the included Node.js bridge. After installation it is located at `components/com_mcpserver/mcp-http-bridge.js` in your Joomla site root. When working from a repository checkout or extracted release zip, use `site/mcp-http-bridge.js` instead.

```bash
node components/com_mcpserver/mcp-http-bridge.js <endpoint-url> [bearer-token]
```

Example:

```bash
node components/com_mcpserver/mcp-http-bridge.js "https://example.com/index.php?option=com_mcpserver&task=rpc.handle" "$MCP_BEARER_TOKEN"
```

### MCP client configuration

For your agent (e.g. Codex, Cursor, Claude, Hermes, OpenClaw), point your MCP client configuration file at the bundled bridge:

```json
{
  "mcpServers": {
    "joomla": {
      "command": "node",
      "args": [
        "/path/to/joomla/components/com_mcpserver/mcp-http-bridge.js",
        "https://example.com/index.php?option=com_mcpserver&task=rpc.handle"
      ],
      "env": {
        "HTTP_AUTH_BEARER": "your-mcp-bearer-token"
      }
    }
  }
}
```

The bridge speaks plain HTTP POST with no SSE or transport negotiation, so connection failures surface their real cause.

#### Windows / Claude Desktop

Claude Desktop on Windows spawns MCP servers with a truncated PATH that excludes the Node.js directory, so `"command": "npx"` (or a bare `"node"`) fails with `spawn npx ENOENT`. Any `cmd.exe` layer — `npx`, `npx.cmd` or `cmd /c` — must also be avoided: `cmd.exe` treats `&` as a command separator and splits the endpoint URL at `&task=`, which the site answers with an HTML 404. Use the absolute path to `node.exe` and invoke the bridge directly. Copy `mcp-http-bridge.js` to the Windows machine first (from `components/com_mcpserver/` in the Joomla site root, or from the release zip):

```json
{
  "mcpServers": {
    "joomla": {
      "command": "C:\\Program Files\\nodejs\\node.exe",
      "args": [
        "C:\\path\\to\\mcp-http-bridge.js",
        "https://example.com/index.php?option=com_mcpserver&task=rpc.handle"
      ],
      "env": {
        "HTTP_AUTH_BEARER": "your-mcp-bearer-token"
      }
    }
  }
}
```

Fully quit Claude Desktop from the tray after editing the configuration — closing the window is not enough.

The bearer token can also be supplied through `HTTP_AUTH_BEARER`. Set `MCP_IGNORE_SSL=1` only for local development with self-signed certificates.

## Release Build

```bash
composer validate --working-dir=admin --no-check-publish
./build.sh
```

## Licence

MCP Server for Joomla is free software released under `GPL-2.0-or-later`.
