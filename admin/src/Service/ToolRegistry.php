<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

class ToolRegistry
{
    /** Repeated verbatim by every com_fields tool schema; the six contexts core Joomla declares. */
    private const FIELD_CONTEXT_DESCRIPTION = 'Joomla custom field context. One of: com_content.article, com_content.categories, com_contact.contact, com_contact.mail, com_contact.categories, com_users.user';

    private array $tools = [];
    private array $executors = [];

    public function __construct()
    {
        $this->registerDefaultTools();
    }

    private function registerDefaultTools(): void
    {
        $this->register([
            'name' => 'get_article_by_id',
            'description' => 'Retrieve a Joomla article by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Article ID',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Article',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'search_articles',
            'description' => 'Search Joomla articles',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Search term'],
                    'language' => ['type' => 'string', 'description' => 'Language code'],
                    'catid' => ['type' => 'integer', 'description' => 'Category ID'],
                    'state' => ['type' => 'integer', 'description' => 'Publication state (1 = published, 0 = unpublished, 2 = archived, -2 = trashed)'],
                    'author' => ['type' => 'integer', 'description' => 'Author user ID (numeric Joomla user id, not a name)'],
                    'featured' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Filter by featured flag'],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'Search Articles',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_article',
            'description' => 'Create a new Joomla article',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'article' => [
                        'type' => 'object',
                        'description' => 'Article data',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'alias' => ['type' => 'string'],
                            'introtext' => ['type' => 'string', 'description' => 'Article intro text (HTML). This is the field Joomla persists; do not use "articletext" or "text".'],
                            'fulltext' => ['type' => 'string', 'description' => 'Article full text (HTML), shown after the read-more break'],
                            'catid' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'state' => ['type' => 'integer'],
                            'version_note' => ['type' => 'string', 'description' => 'Optional note stored with this version in content history'],
                        ],
                        'required' => ['title', 'catid', 'introtext'],
                    ],
                ],
                'required' => ['article'],
            ],
            'annotations' => [
                'title' => 'Create Article',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_article',
            'description' => 'Update an existing Joomla article',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID'],
                    'article' => [
                        'type' => 'object',
                        'description' => 'Article fields to update. Use "introtext" (and optionally "fulltext") for content; "articletext" and "text" are not persisted by the Joomla API. Optional "version_note" is stored in content history when versioning is enabled.',
                    ],
                ],
                'required' => ['id', 'article'],
            ],
            'annotations' => [
                'title' => 'Update Article',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_article',
            'description' => 'Delete a Joomla article. Joomla requires articles to be trashed before deletion, so this tool trashes the article first when needed, then deletes it permanently.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Article',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_article_versions',
            'description' => 'List saved versions (content history) for a Joomla article. Requires article versioning to be enabled in Joomla.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of versions to return (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Number of versions to skip (set to pagination.next_offset when pagination.has_more is true)'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'List Article Versions',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_article_version',
            'description' => 'Retrieve a single article version from content history, including the full version_data snapshot.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'version_id' => ['type' => 'integer', 'description' => 'Content history version ID'],
                ],
                'required' => ['version_id'],
            ],
            'annotations' => [
                'title' => 'Get Article Version',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'diff_article_versions',
            'description' => 'Compare two saved article versions field by field. Text fields (introtext, fulltext) include a unified line diff. Requires article versioning to be enabled in Joomla.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID'],
                    'version_id_from' => ['type' => 'integer', 'description' => 'Content history version ID to diff from'],
                    'version_id_to' => ['type' => 'integer', 'description' => 'Content history version ID to diff to'],
                ],
                'required' => ['id', 'version_id_from', 'version_id_to'],
            ],
            'annotations' => [
                'title' => 'Diff Article Versions',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'keep_article_version',
            'description' => 'Toggle the "keep forever" flag on an article version so Joomla will not prune it automatically.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'version_id' => ['type' => 'integer', 'description' => 'Content history version ID'],
                ],
                'required' => ['version_id'],
            ],
            'annotations' => [
                'title' => 'Keep Article Version',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_article_version',
            'description' => 'Delete a single article version from content history. Versions marked "keep forever" cannot be deleted.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'version_id' => ['type' => 'integer', 'description' => 'Content history version ID'],
                ],
                'required' => ['version_id'],
            ],
            'annotations' => [
                'title' => 'Delete Article Version',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'restore_article_version',
            'description' => 'Restore a Joomla article to a previous saved version from content history. Creates a new version for the current state before applying the restored content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID to restore'],
                    'version_id' => ['type' => 'integer', 'description' => 'Content history version ID to restore from'],
                    'version_note' => ['type' => 'string', 'description' => 'Optional note for the version created by this restore'],
                ],
                'required' => ['id', 'version_id'],
            ],
            'annotations' => [
                'title' => 'Restore Article Version',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_custom_module',
            'description' => 'Create a new Joomla "Custom" (mod_custom) module',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Module title'],
                    'content' => ['type' => 'string', 'description' => 'HTML content for the module'],
                    'position' => ['type' => 'string', 'description' => 'Template position (e.g. "sidebar-right")'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'Create as a site or administrator module',
                    ],
                    'published' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'default' => 1,
                        'description' => 'Published state (0 = unpublished, 1 = published)',
                    ],
                    'access' => [
                        'type' => 'integer',
                        'default' => 1,
                        'description' => 'Access level ID (1 = Public, 2 = Registered, etc.)',
                    ],
                    'language' => [
                        'type' => 'string',
                        'default' => '*',
                        'description' => 'Language code (e.g. "en-GB") or "*" for all',
                    ],
                    'note' => ['type' => 'string', 'description' => 'Optional admin note'],
                    'ordering' => ['type' => 'integer', 'description' => 'Module ordering within the position'],
                ],
                'required' => ['title', 'content', 'position'],
            ],
            'annotations' => [
                'title' => 'Create Custom Module',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_custom_modules',
            'description' => 'List all Joomla "Custom" (mod_custom) modules',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'List site or administrator modules',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Custom Modules',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_custom_module_by_id',
            'description' => 'Retrieve a Joomla "Custom" module by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Module ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Custom Module',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_custom_module',
            'description' => 'Update the content of a Joomla "Custom" module',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Module ID'],
                    'content' => ['type' => 'string', 'description' => 'The HTML content for the module'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id', 'content'],
            ],
            'annotations' => [
                'title' => 'Update Custom Module',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_menus',
            'description' => 'List all Joomla menus (menu types)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'List site or administrator menus',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Menus',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_menu_items',
            'description' => 'List menu items, optionally filtered by menu type',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'menutype' => ['type' => 'string', 'description' => 'Menu type alias to filter by (e.g. "mainmenu")'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'List site or administrator menu items',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Menu Items',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_modules',
            'description' => 'List all Joomla modules',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'List site or administrator modules',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Modules',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_module_by_id',
            'description' => 'Retrieve a Joomla module by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Module ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Module',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_module',
            'description' => 'Update any Joomla module (works for all module types). Only the fields you supply are changed; '
                . '"params" is the module type\'s own settings and is merged into the existing params (send only the keys you '
                . 'want to change). "assignment"/"assigned" set which pages the module shows on and are left untouched when '
                . 'omitted. Call get_module_by_id first to inspect a module\'s current params and page assignment.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Module ID'],
                    'title' => ['type' => 'string', 'description' => 'Module title'],
                    'position' => ['type' => 'string', 'description' => 'Template position (e.g. "sidebar-right")'],
                    'content' => ['type' => 'string', 'description' => 'Custom HTML content (only used by content-bearing modules such as mod_custom)'],
                    'published' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Published state (0 = unpublished, 1 = published)',
                    ],
                    'access' => ['type' => 'integer', 'description' => 'Access level ID (1 = Public, 2 = Registered, etc.)'],
                    'showtitle' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Whether to show the module title (0 = hide, 1 = show)',
                    ],
                    'ordering' => ['type' => 'integer', 'description' => 'Module ordering within the position'],
                    'language' => ['type' => 'string', 'description' => 'Language code (e.g. "en-GB") or "*" for all'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'params' => [
                        'type' => 'object',
                        'description' => 'Module type-specific settings, merged into the existing params. Send only the keys to change.',
                        'additionalProperties' => true,
                    ],
                    'assignment' => [
                        'enum' => [0, 1, -1, '-'],
                        'description' => 'Menu (page) assignment mode: 0 = all pages, 1 = only the menu items listed in "assigned", '
                            . '-1 = all pages except those menu items, "-" = no pages at all. Required whenever "assigned" is sent. '
                            . 'Site modules only. Omit both fields to leave the current assignment untouched.',
                    ],
                    'assigned' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Menu item IDs (Itemid) the assignment applies to; replaces the module\'s current selection. '
                            . 'Required when assignment is 1 or -1, and must be omitted for assignment 0 and "-".',
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Update Module',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_menu_item',
            'description' => 'Retrieve a Joomla menu item by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Menu item ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Menu Item',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_menu_item',
            'description' => 'Create a new Joomla menu item',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Menu item title'],
                    'menutype' => ['type' => 'string', 'description' => 'Menu type alias (e.g. "mainmenu")'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['component', 'url', 'alias', 'separator', 'heading'],
                        'default' => 'component',
                        'description' => 'Menu item type',
                    ],
                    'link' => ['type' => 'string', 'description' => 'URL or component link (e.g. "index.php?option=com_content&view=article&id=1"). For component menu items, include all required query parameters (such as the article id) in the link.'],
                    'component_id' => ['type' => 'integer', 'description' => 'Component ID (required for "component" type items)'],
                    'parent_id' => ['type' => 'integer', 'default' => 1, 'description' => 'Parent menu item ID (1 = root)'],
                    'published' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'default' => 1,
                        'description' => 'Published state',
                    ],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'Access level ID'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'alias' => ['type' => 'string', 'description' => 'URL alias'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'browserNav' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'default' => 0,
                        'description' => 'Target window (0 = parent, 1 = new window, 2 = new without navigation)',
                    ],
                    'home' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 0, 'description' => 'Set as default page'],
                    'params' => ['type' => 'object', 'description' => 'Menu item parameters'],
                    'request' => [
                        'type' => 'object',
                        'description' => 'Request parameters required by the selected component view (e.g. {"id": 2} for a single article menu item linking to com_content article view)',
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['title', 'menutype', 'type'],
            ],
            'annotations' => [
                'title' => 'Create Menu Item',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_menu_item',
            'description' => 'Update an existing Joomla menu item',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Menu item ID'],
                    'menu_item' => [
                        'type' => 'object',
                        'description' => 'Menu item fields to update',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'alias' => ['type' => 'string'],
                            'link' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'published' => ['type' => 'integer'],
                            'access' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'parent_id' => ['type' => 'integer'],
                            'menutype' => ['type' => 'string'],
                            'browserNav' => ['type' => 'integer'],
                            'home' => ['type' => 'integer'],
                            'params' => ['type' => 'object'],
                            'request' => ['type' => 'object'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id', 'menu_item'],
            ],
            'annotations' => [
                'title' => 'Update Menu Item',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_media',
            'description' => 'List Joomla media files and folders. Paths may include an adapter prefix (e.g. "local-images:/banners"); without one, "local-images" (the /images folder) is assumed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Folder path to list (default: media root)'],
                    'search' => ['type' => 'string', 'description' => 'Search term filtering file/folder names'],
                    'include_url' => ['type' => 'boolean', 'default' => true, 'description' => 'Include a public URL attribute on file entries'],
                    'include_temp' => ['type' => 'boolean', 'default' => false, 'description' => 'Include a temporary URL attribute on file entries'],
                ],
            ],
            'annotations' => [
                'title' => 'List Media',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_media',
            'description' => 'Retrieve a single Joomla media file or folder by path',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File or folder path (e.g. "banners/logo.png" or "local-images:/banners/logo.png")'],
                    'include_content' => ['type' => 'boolean', 'default' => false, 'description' => 'Include the file contents as base64 (omitted by default to avoid large responses)'],
                    'include_url' => ['type' => 'boolean', 'default' => true, 'description' => 'Include a public URL attribute'],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'title' => 'Get Media',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'upload_media',
            'description' => 'Upload a new Joomla media file. Provide either base64 "content" or a "source_url" the server will fetch and encode. Cannot overwrite an existing file (use update_media for that).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Destination path including filename (e.g. "banners/logo.png")'],
                    'content' => ['type' => 'string', 'description' => 'Base64-encoded file contents'],
                    'source_url' => ['type' => 'string', 'description' => 'URL the server will download and base64-encode'],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'title' => 'Upload Media',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_media_folder',
            'description' => 'Create a new folder in the Joomla media library',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Folder path including the new folder name (e.g. "banners/2026")'],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'title' => 'Create Media Folder',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_media',
            'description' => 'Rename, move or replace the contents of an existing Joomla media file or folder. Provide at least one of "new_path", "content" or "source_url".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Current file or folder path'],
                    'new_path' => ['type' => 'string', 'description' => 'New path to move or rename to'],
                    'content' => ['type' => 'string', 'description' => 'Replacement file contents as base64'],
                    'source_url' => ['type' => 'string', 'description' => 'URL the server will download and base64-encode as the replacement contents'],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'title' => 'Update Media',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_media',
            'description' => 'Delete a Joomla media file or folder by path',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File or folder path'],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'title' => 'Delete Media',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_content_languages',
            'description' => 'List Joomla content languages (the language tags assignable to articles, menu items, etc.)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'published' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Filter by published state',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Content Languages',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_content_language',
            'description' => 'Retrieve a Joomla content language by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Content language ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Content Language',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_content_language',
            'description' => 'Create a new Joomla content language',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'lang_code' => ['type' => 'string', 'description' => 'Language code (e.g. "en-GB")'],
                    'title' => ['type' => 'string', 'description' => 'Display title (e.g. "English (United Kingdom)")'],
                    'title_native' => ['type' => 'string', 'description' => 'Native name (e.g. "English")'],
                    'sef' => ['type' => 'string', 'description' => 'SEF prefix (e.g. "en")'],
                    'image' => ['type' => 'string', 'description' => 'Flag image identifier (e.g. "en")'],
                    'description' => ['type' => 'string'],
                    'metakey' => ['type' => 'string'],
                    'metadesc' => ['type' => 'string'],
                    'sitename' => ['type' => 'string', 'description' => 'Optional site name override for this language'],
                    'published' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1],
                    'access' => ['type' => 'integer', 'default' => 1],
                    'ordering' => ['type' => 'integer'],
                ],
                'required' => ['lang_code', 'title', 'title_native', 'sef'],
            ],
            'annotations' => [
                'title' => 'Create Content Language',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_content_language',
            'description' => 'Update an existing Joomla content language',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Content language ID'],
                    'language' => [
                        'type' => 'object',
                        'description' => 'Content language fields to update',
                        'properties' => [
                            'lang_code' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'title_native' => ['type' => 'string'],
                            'sef' => ['type' => 'string'],
                            'image' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'metakey' => ['type' => 'string'],
                            'metadesc' => ['type' => 'string'],
                            'sitename' => ['type' => 'string'],
                            'published' => ['type' => 'integer'],
                            'access' => ['type' => 'integer'],
                            'ordering' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'required' => ['id', 'language'],
            ],
            'annotations' => [
                'title' => 'Update Content Language',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_content_language',
            'description' => 'Delete a Joomla content language by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Content language ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Content Language',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_installed_languages',
            'description' => 'List languages installed on the Joomla site (both site and administrator clients). Read-only view of `#__extensions` plus the application default language tag.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'description' => 'Filter to a single client; omit to return both',
                    ],
                ],
            ],
            'annotations' => [
                'title' => 'List Installed Languages',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_template_styles',
            'description' => 'List Joomla template styles (rows from `#__template_styles`) for the chosen client. Each style is a configurable instance of an installed template.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                        'description' => 'List site or administrator template styles',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Template Styles',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_template_style',
            'description' => 'Retrieve a Joomla template style by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Template style ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Template Style',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_template_style',
            'description' => 'Create a new Joomla template style for an already-installed template. The `template` field is the template element name (e.g. "cassiopeia" or "atum"), not a display title.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'template' => ['type' => 'string', 'description' => 'Template element name (e.g. "cassiopeia"). Must match an installed template for the chosen client.'],
                    'title' => ['type' => 'string', 'description' => 'Display title for the style'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                    'home' => [
                        'type' => 'string',
                        'default' => '0',
                        'description' => 'Default-style flag: "0" = not default, "1" or "*" = default for all languages, or a language tag (e.g. "en-GB") to be the default for that language',
                    ],
                    'inheritable' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 0, 'description' => 'Whether this style can be inherited by child styles'],
                    'parent' => ['type' => 'string', 'default' => '', 'description' => 'Parent style id when inheriting, "" for none'],
                    'params' => ['type' => 'object', 'description' => 'Template parameters (template-specific JSON)'],
                ],
                'required' => ['template', 'title'],
            ],
            'annotations' => [
                'title' => 'Create Template Style',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_template_style',
            'description' => 'Update an existing Joomla template style. The `template` field cannot be changed; create a new style if you need a different template.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Template style ID'],
                    'style' => [
                        'type' => 'object',
                        'description' => 'Template style fields to update',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'home' => ['type' => 'string', 'description' => '"0", "1"/"*" or a language tag'],
                            'inheritable' => ['type' => 'integer', 'enum' => [0, 1]],
                            'parent' => ['type' => 'string'],
                            'params' => ['type' => 'object'],
                        ],
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id', 'style'],
            ],
            'annotations' => [
                'title' => 'Update Template Style',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_template_style',
            'description' => 'Delete a Joomla template style. The default style for a template cannot be deleted.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Template style ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Template Style',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'install_extension',
            'description' => 'Install a Joomla extension from a zip package. '
                . 'Provide exactly one of: `source_url` (preferred — server downloads the package), '
                . '`source_path` (absolute path to a .zip already on the Joomla server), '
                . 'or base64 `content` (small packages only). '
                . 'Do not read local .zip files in the MCP client and pass them as content — MCP clients cannot read large binary files. '
                . 'WARNING: this is arbitrary code execution — only allow trusted callers.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded contents of the .zip package (small packages only; prefer source_url or source_path)',
                    ],
                    'source_url' => [
                        'type' => 'string',
                        'description' => 'URL the server will download (preferred for release packages and GitHub assets)',
                    ],
                    'source_path' => [
                        'type' => 'string',
                        'description' => 'Absolute path on the Joomla server to an existing .zip file (must be under tmp_path or the site root)',
                    ],
                ],
            ],
            'annotations' => [
                'title' => 'Install Extension',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_installed_templates',
            'description' => 'List templates installed on the Joomla site (both site and administrator clients). Read-only view of `#__extensions` filtered to type=template.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'description' => 'Filter to a single client; omit to return both',
                    ],
                ],
            ],
            'annotations' => [
                'title' => 'List Installed Templates',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_template_files',
            'description' => 'List the editable source files of an installed template (the "Customise" view of Joomla\'s template manager). '
                . 'Use `extension_id` from list_installed_templates. Returns file paths relative to the template root (e.g. "index.php", '
                . '"css/template.css", "html/com_content/article/default.php"). Set `media` to true to list the template\'s media folder instead.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Template extension ID (from list_installed_templates)'],
                    'media' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'List the media/templates folder instead of the template folder',
                    ],
                ],
                'required' => ['extension_id'],
            ],
            'annotations' => [
                'title' => 'List Template Files',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_template_file',
            'description' => 'Read the source of a single template file. `path` is relative to the template root, as returned by '
                . 'list_template_files (e.g. "html/com_content/article/default.php"). Set `media` to true to read from the template\'s media folder.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Template extension ID'],
                    'path' => ['type' => 'string', 'description' => 'File path relative to the template root'],
                    'media' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Read from the media/templates folder instead',
                    ],
                ],
                'required' => ['extension_id', 'path'],
            ],
            'annotations' => [
                'title' => 'Get Template File',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_template_file',
            'description' => 'Write the source of a template file (Joomla\'s template "Customise" editor). Overwrites the file if it exists, '
                . 'otherwise creates it, provided its parent directory already exists — use create_template_override to add a new override '
                . 'folder first. The response reports `created`. Line endings are normalised to Unix, and joomla.asset.json '
                . 'must remain valid JSON. `path` is relative to the template root. '
                . 'WARNING: this can write executable PHP into the site — arbitrary code execution; only allow trusted callers.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Template extension ID'],
                    'path' => ['type' => 'string', 'description' => 'File path relative to the template root'],
                    'source' => ['type' => 'string', 'description' => 'The complete new file contents'],
                    'media' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Write to the media/templates folder instead',
                    ],
                ],
                'required' => ['extension_id', 'path', 'source'],
            ],
            'annotations' => [
                'title' => 'Update Template File',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_template_override',
            'description' => 'Create a template override by copying a core component view, module, plugin or layout into the template\'s html/ folder, '
                . 'exactly as Joomla\'s "Create Overrides" tab does. `source` is the folder to override, relative to the Joomla root, e.g. '
                . '"components/com_content/tmpl/article" (a component view), "modules/mod_menu" (a module), "layouts/joomla/content" (a layout) '
                . 'or "plugins/system/example". After creating the override, use list_template_files / get_template_file / update_template_file to edit it.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Template extension ID that will receive the override'],
                    'source' => [
                        'type' => 'string',
                        'description' => 'Folder to override, relative to the Joomla root (e.g. "components/com_content/tmpl/article", "modules/mod_menu", "layouts/joomla/content")',
                    ],
                ],
                'required' => ['extension_id', 'source'],
            ],
            'annotations' => [
                'title' => 'Create Template Override',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_article_associations',
            'description' => 'List the cross-language associations for a Joomla article. Returns the sibling articles that share an association key in context "com_content.item".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Primary article ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'List Article Associations',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'set_article_associations',
            'description' => 'Set the cross-language associations for a Joomla article. Each item in `associated_ids` must use a different language from the primary article and from each other. Passing an empty `associated_ids` clears all associations for the primary.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Primary article ID'],
                    'associated_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Article IDs to associate with the primary (one per other language)',
                    ],
                ],
                'required' => ['id', 'associated_ids'],
            ],
            'annotations' => [
                'title' => 'Set Article Associations',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_menu_item_associations',
            'description' => 'List the cross-language associations for a Joomla site menu item. Returns the sibling menu items that share an association key in context "com_menus.item".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Primary menu item ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'List Menu Item Associations',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'set_menu_item_associations',
            'description' => 'Set the cross-language associations for a Joomla site menu item. Each item in `associated_ids` must use a different language from the primary menu item and from each other. Passing an empty `associated_ids` clears all associations for the primary. Only applies to site (client_id=0) menu items.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Primary menu item ID'],
                    'associated_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Menu item IDs to associate with the primary (one per other language)',
                    ],
                ],
                'required' => ['id', 'associated_ids'],
            ],
            'annotations' => [
                'title' => 'Set Menu Item Associations',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_categories',
            'description' => 'List Joomla content (article) categories. Use this to discover valid catid values before creating or searching articles.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Categories',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_category',
            'description' => 'Retrieve a Joomla content category by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Category ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Category',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_category',
            'description' => 'Create a new Joomla content (article) category',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Category title'],
                    'parent_id' => ['type' => 'integer', 'default' => 1, 'description' => 'Parent category ID (1 = root)'],
                    'alias' => ['type' => 'string', 'description' => 'URL alias'],
                    'description' => ['type' => 'string', 'description' => 'Category description (HTML)'],
                    'published' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'Access level ID'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                ],
                'required' => ['title'],
            ],
            'annotations' => [
                'title' => 'Create Category',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_category',
            'description' => 'Update an existing Joomla content category',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Category ID'],
                    'category' => [
                        'type' => 'object',
                        'description' => 'Category fields to update',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'alias' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'parent_id' => ['type' => 'integer'],
                            'published' => ['type' => 'integer'],
                            'access' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['id', 'category'],
            ],
            'annotations' => [
                'title' => 'Update Category',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_category',
            'description' => 'Delete a Joomla content category. Joomla requires categories to be trashed before deletion, so this tool trashes the category first when needed, then deletes it permanently. The category must be empty (no articles or subcategories).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Category ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Category',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_tags',
            'description' => 'List Joomla tags',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Tags',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_tag',
            'description' => 'Retrieve a Joomla tag by ID',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Tag ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Get Tag',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_tag',
            'description' => 'Create a new Joomla tag',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Tag title'],
                    'parent_id' => ['type' => 'integer', 'default' => 1, 'description' => 'Parent tag ID (1 = root)'],
                    'alias' => ['type' => 'string', 'description' => 'URL alias'],
                    'description' => ['type' => 'string', 'description' => 'Tag description (HTML)'],
                    'published' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'Access level ID'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                ],
                'required' => ['title'],
            ],
            'annotations' => [
                'title' => 'Create Tag',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_tag',
            'description' => 'Update an existing Joomla tag',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Tag ID'],
                    'tag' => [
                        'type' => 'object',
                        'description' => 'Tag fields to update',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'alias' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'parent_id' => ['type' => 'integer'],
                            'published' => ['type' => 'integer'],
                            'access' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['id', 'tag'],
            ],
            'annotations' => [
                'title' => 'Update Tag',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_tag',
            'description' => 'Delete a Joomla tag. The tag is trashed first when needed, then deleted permanently.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Tag ID'],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Tag',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_extensions',
            'description' => 'List extensions installed on the Joomla site (read-only view of `#__extensions`). Filter by type to find plugins, modules, components, templates, languages, libraries or packages. Use the returned extension_id with set_extension_state or uninstall_extension.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['component', 'module', 'plugin', 'template', 'language', 'library', 'package', 'file'],
                        'description' => 'Filter by extension type',
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'description' => 'Filter to a single client; omit to return both',
                    ],
                    'enabled' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Filter by enabled state'],
                    'folder' => ['type' => 'string', 'description' => 'Plugin group folder (e.g. "system", "content", "webservices")'],
                    'search' => ['type' => 'string', 'description' => 'Match against extension name or element'],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'List Extensions',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'set_extension_state',
            'description' => 'Enable or disable an installed Joomla extension (e.g. activate a plugin after install_extension — Joomla installs plugins disabled by default). Protected core extensions cannot be disabled.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Extension ID (from list_extensions)'],
                    'enabled' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = enable, 0 = disable'],
                ],
                'required' => ['extension_id', 'enabled'],
            ],
            'annotations' => [
                'title' => 'Set Extension State',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'uninstall_extension',
            'description' => 'Uninstall a Joomla extension by extension_id. Protected and locked core extensions cannot be uninstalled. WARNING: this permanently removes the extension and runs its uninstall scripts — only allow trusted callers.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extension_id' => ['type' => 'integer', 'description' => 'Extension ID (from list_extensions)'],
                ],
                'required' => ['extension_id'],
            ],
            'annotations' => [
                'title' => 'Uninstall Extension',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_menu',
            'description' => 'Create a new Joomla menu (menu type). Add items to it afterwards with create_menu_item.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Menu title'],
                    'menutype' => ['type' => 'string', 'description' => 'Unique menu type alias (e.g. "footermenu")'],
                    'description' => ['type' => 'string', 'description' => 'Menu description'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['title', 'menutype'],
            ],
            'annotations' => [
                'title' => 'Create Menu',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_menu_item',
            'description' => 'Delete a Joomla menu item. Joomla requires menu items to be trashed before deletion, so this tool trashes the item first when needed, then deletes it permanently. The default (home) menu item cannot be deleted.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Menu item ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Menu Item',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_module',
            'description' => 'Create a new Joomla module of any installed type (e.g. mod_menu, mod_login, mod_articles_latest). The module type must already be installed for the chosen client; check with list_extensions (type "module"). Type-specific settings go in "params" — call get_module_by_id on an existing module of the same type to see its parameter names. The module is assigned to all pages.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Module title'],
                    'module' => ['type' => 'string', 'description' => 'Module element name (e.g. "mod_menu")'],
                    'position' => ['type' => 'string', 'description' => 'Template position (e.g. "sidebar-right")'],
                    'content' => ['type' => 'string', 'description' => 'HTML content (only used by content-bearing modules such as mod_custom)'],
                    'params' => [
                        'type' => 'object',
                        'description' => 'Module type-specific settings',
                        'additionalProperties' => true,
                    ],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                    'published' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'Access level ID'],
                    'showtitle' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1, 'description' => 'Whether to show the module title'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'ordering' => ['type' => 'integer', 'description' => 'Module ordering within the position'],
                ],
                'required' => ['title', 'module', 'position'],
            ],
            'annotations' => [
                'title' => 'Create Module',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_module',
            'description' => 'Delete a Joomla module by ID (any module type). Removes the module and its page assignments permanently.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Module ID'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator'],
                        'default' => 'site',
                    ],
                ],
                'required' => ['id'],
            ],
            'annotations' => [
                'title' => 'Delete Module',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'clear_cache',
            'description' => 'Clear Joomla\'s system cache so recent changes become visible on the site (with caching enabled, Joomla can keep serving stale pages until the cache expires). Clears every cache group by default; pass "group" to clear a single one (e.g. "page", "com_content", "com_modules"). The MCP server\'s own session and rate-limit caches are always preserved.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group' => ['type' => 'string', 'description' => 'Single cache group to clear (e.g. "page", "com_content"); omit to clear all groups'],
                    'client' => [
                        'type' => 'string',
                        'enum' => ['site', 'administrator', 'both'],
                        'default' => 'both',
                        'description' => 'Which application cache to clear',
                    ],
                ],
            ],
            'annotations' => [
                'title' => 'Clear Cache',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_rendered_page',
            'description' => 'Fetch the HTML a guest visitor sees for an article or menu item. Builds a canonical non-SEF URL and follows same-host redirects to the SEF URL. Anonymous fetch (no API token), capped at 512 KB, 30 second timeout. Provide exactly one of article_id or menu_item_id.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'article_id' => ['type' => 'integer', 'description' => 'Article ID to render'],
                    'menu_item_id' => ['type' => 'integer', 'description' => 'Menu item ID to render'],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['html', 'text'],
                        'default' => 'html',
                        'description' => 'Return raw HTML or extracted text',
                    ],
                ],
            ],
            'annotations' => [
                'title' => 'Get Rendered Page',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'seo_audit_articles',
            'description' => 'Audit published articles for SEO metadata issues: missing or empty title, missing metadesc, short metadesc (under 50 characters), long metadesc (over 160 characters), and duplicate aliases within the same category. The obsolete metakey field is not checked (unused since Joomla 1.6 / 2009). Analyses up to 5,000 published articles.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'SEO Audit Articles',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'check_internal_links',
            'description' => 'Check hyperlinks and media URLs in article HTML without sending HTTP requests. Internal links are resolved offline against published and unpublished articles and site menu paths (status: ok, missing, unpublished, or unknown — unknown means not resolvable offline, not that the link is broken). External links are never probed (status: not_checked). Pass article_id to check one article; omit to scan published articles (up to 5,000).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'article_id' => ['type' => 'integer', 'description' => 'Check a single article; omit to scan published articles'],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
            ],
            'annotations' => [
                'title' => 'Check Internal Links',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_field_groups',
            'description' => 'List custom field groups (the tabs that custom fields are organised into) for a Joomla field context. Call this before create_field or update_field to discover valid group_id values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2], 'description' => 'Filter by state (1 published, 0 unpublished, 2 archived, -2 trashed)'],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
                'required' => ['context'],
            ],
            'annotations' => [
                'title' => 'List Field Groups',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_field_group',
            'description' => 'Get a single custom field group by ID, including its params and view access level. Per-group permission rules are not exposed by Joomla\'s Web Services API, so they can be neither read nor changed through this server.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field group ID'],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Get Field Group',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_field_group',
            'description' => 'Create a custom field group (a tab) in a Joomla field context.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'title' => ['type' => 'string', 'description' => 'Field group title, shown as the tab label'],
                    'description' => ['type' => 'string', 'description' => 'Field group description (HTML)'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'state' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1, 'description' => 'Published state (1 published, 0 unpublished)'],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'View access level ID'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'params' => ['type' => 'object', 'description' => 'Field group parameters', 'additionalProperties' => true],
                ],
                'required' => ['context', 'title'],
            ],
            'annotations' => [
                'title' => 'Create Field Group',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_field_group',
            'description' => 'Update a custom field group. Only the fields you supply are changed; "params" is merged into the existing params (send only the keys you want to change). Permission rules are never touched. Call get_field_group first to inspect the current values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field group ID'],
                    'title' => ['type' => 'string', 'description' => 'Field group title'],
                    'description' => ['type' => 'string', 'description' => 'Field group description (HTML)'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2], 'description' => 'State (1 published, 0 unpublished, 2 archived, -2 trashed)'],
                    'access' => ['type' => 'integer', 'description' => 'View access level ID'],
                    'language' => ['type' => 'string', 'description' => 'Language code or "*" for all'],
                    'ordering' => ['type' => 'integer', 'description' => 'Sort order within the context'],
                    'params' => ['type' => 'object', 'description' => 'Field group parameters, merged into the existing params. Send only the keys to change.', 'additionalProperties' => true],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Update Field Group',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_field_group',
            'description' => 'Delete a custom field group. Joomla requires field groups to be trashed before deletion, so this tool trashes the group first when needed, then deletes it permanently. Fields assigned to the group are not deleted; move them to another group with update_field beforehand.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field group ID'],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Delete Field Group',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'reorder_field_groups',
            'description' => 'Reorder custom field groups (tabs) within a context. Supply every group ID in the order you want; each is assigned an ordering of 1..N. IDs are validated against the context before anything is written.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'ordered_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Field group IDs in the desired order',
                    ],
                ],
                'required' => ['context', 'ordered_ids'],
            ],
            'annotations' => [
                'title' => 'Reorder Field Groups',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'list_fields',
            'description' => 'List custom fields defined for a Joomla field context. Use this to discover which fields exist, their technical names and their IDs.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'group_id' => ['type' => 'integer', 'description' => 'Only fields in this field group'],
                    'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2], 'description' => 'Filter by state (1 published, 0 unpublished, 2 archived, -2 trashed)'],
                    'search' => ['type' => 'string', 'description' => 'Partial match against title, technical name or admin note'],
                    'limit' => ['type' => 'integer', 'description' => 'Results limit (use with offset to page; check pagination.has_more and pagination.next_offset in the response)'],
                    'offset' => ['type' => 'integer', 'description' => 'Results offset (set to pagination.next_offset when pagination.has_more is true)'],
                ],
                'required' => ['context'],
            ],
            'annotations' => [
                'title' => 'List Fields',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_field',
            'description' => 'Get a single custom field by ID. Returns the full definition plus an "options" array giving each selectable option as a separate stored "value" and human-readable "label" (list, radio and checkboxes fields store only the value; the label is editable in the admin and is not the value). The raw "fieldparams" are returned unchanged alongside it. Per-field permission rules are not exposed by Joomla\'s Web Services API, so they can be neither read nor changed through this server.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field ID'],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Get Field',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'find_field_by_name',
            'description' => 'Resolve a custom field\'s technical name to its full definition within a context. Use this instead of guessing IDs when you know a field\'s name. Matching is exact and case-insensitive; use list_fields with "search" for partial matches.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'name' => ['type' => 'string', 'description' => 'The field\'s technical name (its alias, not its title or label)'],
                ],
                'required' => ['context', 'name'],
            ],
            'annotations' => [
                'title' => 'Find Field By Name',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'create_field',
            'description' => 'Create a custom field in a Joomla field context. "type" must match an installed field plugin; the core types are calendar, checkboxes, color, editor, imagelist, integer, list, media, radio, sql, subform, text, textarea, url, user and usergrouplist. Type-specific settings go in "fieldparams" — for list, radio and checkboxes that means fieldparams.options, where each option carries a stored "value" and a displayed "name".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'title' => ['type' => 'string', 'description' => 'Field title, shown in the admin list'],
                    'type' => ['type' => 'string', 'default' => 'text', 'description' => 'Field type (see the tool description for the core types)'],
                    'name' => ['type' => 'string', 'description' => 'Technical name; generated from the title when omitted'],
                    'label' => ['type' => 'string', 'description' => 'Label shown on the edit form; defaults to the title'],
                    'group_id' => ['type' => 'integer', 'description' => 'Field group (tab) ID; 0 for no group'],
                    'description' => ['type' => 'string', 'description' => 'Field description (HTML)'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'default_value' => ['type' => 'string', 'description' => 'Default value applied to new items'],
                    'required' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 0, 'description' => 'Whether the field must be filled in'],
                    'only_use_in_subform' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Restrict the field to use inside a subform field'],
                    'state' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 1, 'description' => 'Published state (1 published, 0 unpublished)'],
                    'access' => ['type' => 'integer', 'default' => 1, 'description' => 'View access level ID'],
                    'language' => ['type' => 'string', 'default' => '*', 'description' => 'Language code or "*" for all'],
                    'ordering' => ['type' => 'integer', 'description' => 'Sort order within the context'],
                    'assigned_cat_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Category IDs the field applies to. Omit or send [0] for all categories; send [-1] for none. Only meaningful for contexts that have categories.',
                    ],
                    'params' => ['type' => 'object', 'description' => 'Display and form parameters (showlabel, render_class, display, showon, ...)', 'additionalProperties' => true],
                    'fieldparams' => ['type' => 'object', 'description' => 'Type-specific settings, e.g. options for list/radio/checkboxes', 'additionalProperties' => true],
                ],
                'required' => ['context', 'title'],
            ],
            'annotations' => [
                'title' => 'Create Field',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'update_field',
            'description' => 'Update a custom field. Only the fields you supply are changed. "params" and "fieldparams" are each merged into the existing values, so send only the keys you want to change. Category assignments are preserved when "assigned_cat_ids" is omitted, and permission rules are never touched. WARNING: replacing fieldparams.options on a list, radio or checkboxes field makes Joomla delete every stored value that is no longer one of the options. Call get_field first to inspect the current definition.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field ID'],
                    'title' => ['type' => 'string', 'description' => 'Field title'],
                    'type' => ['type' => 'string', 'description' => 'Field type (see create_field for the core types)'],
                    'name' => ['type' => 'string', 'description' => 'Technical name'],
                    'label' => ['type' => 'string', 'description' => 'Label shown on the edit form'],
                    'group_id' => ['type' => 'integer', 'description' => 'Field group (tab) ID; 0 for no group'],
                    'description' => ['type' => 'string', 'description' => 'Field description (HTML)'],
                    'note' => ['type' => 'string', 'description' => 'Admin note'],
                    'default_value' => ['type' => 'string', 'description' => 'Default value applied to new items'],
                    'required' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Whether the field must be filled in'],
                    'only_use_in_subform' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Restrict the field to use inside a subform field'],
                    'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2], 'description' => 'State (1 published, 0 unpublished, 2 archived, -2 trashed)'],
                    'access' => ['type' => 'integer', 'description' => 'View access level ID'],
                    'language' => ['type' => 'string', 'description' => 'Language code or "*" for all'],
                    'ordering' => ['type' => 'integer', 'description' => 'Sort order within the context'],
                    'assigned_cat_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Category IDs the field applies to. Left unchanged when omitted. Send [0] for all categories, [-1] for none.',
                    ],
                    'params' => ['type' => 'object', 'description' => 'Display and form parameters, merged into the existing params. Send only the keys to change.', 'additionalProperties' => true],
                    'fieldparams' => ['type' => 'object', 'description' => 'Type-specific settings, merged into the existing fieldparams. Send only the keys to change; a supplied "options" replaces the whole option list.', 'additionalProperties' => true],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Update Field',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'delete_field',
            'description' => 'Delete a custom field, every value stored for it and its category assignments. Joomla requires fields to be trashed before deletion, so this tool trashes the field first when needed, then deletes it permanently.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'id' => ['type' => 'integer', 'description' => 'Field ID'],
                ],
                'required' => ['context', 'id'],
            ],
            'annotations' => [
                'title' => 'Delete Field',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'reorder_fields',
            'description' => 'Reorder custom fields within a context. Supply every field ID in the order you want; each is assigned an ordering of 1..N. IDs are validated against the context before anything is written.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => self::FIELD_CONTEXT_DESCRIPTION],
                    'ordered_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Field IDs in the desired order',
                    ],
                ],
                'required' => ['context', 'ordered_ids'],
            ],
            'annotations' => [
                'title' => 'Reorder Fields',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'get_item_field_values',
            'description' => 'Read the custom field values stored on one item (an article, category, contact or user). Each entry reports the field\'s technical name and label, the "raw_value" actually stored, and the "display_value" that value resolves to — for list, radio and checkboxes fields these differ, because only the value is stored. Not available for com_contact.mail, which has no stored items.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => 'Field context of the item. One of: com_content.article, com_content.categories, com_contact.contact, com_users.user'],
                    'item_id' => ['type' => 'integer', 'description' => 'ID of the article, category, contact or user'],
                ],
                'required' => ['context', 'item_id'],
            ],
            'annotations' => [
                'title' => 'Get Item Field Values',
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);

        $this->register([
            'name' => 'set_item_field_values',
            'description' => 'Set custom field values on one item (an article, contact or user). "values" is keyed by each field\'s technical name — use find_field_by_name or list_fields to resolve names first; unknown names are rejected rather than silently ignored. Fields you do not mention keep their current values. For list, radio and checkboxes fields send the stored option value, not its label. Sending an empty string or empty array clears that field\'s value. Joomla\'s Web Services API cannot write field values for com_content.categories or com_contact.mail, so those contexts are rejected.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => ['type' => 'string', 'description' => 'Field context of the item. One of: com_content.article, com_contact.contact, com_users.user'],
                    'item_id' => ['type' => 'integer', 'description' => 'ID of the article, contact or user'],
                    'values' => [
                        'type' => 'object',
                        'description' => 'Field values keyed by the field\'s technical name',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['context', 'item_id', 'values'],
            ],
            'annotations' => [
                'title' => 'Set Item Field Values',
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ]);
    }

    public function register(array $tool): void
    {
        $this->tools[$tool['name']] = $tool;
    }

    public function setExecutor(string $name, callable $executor): void
    {
        $this->executors[$name] = $executor;
    }

    public function execute(string $name, array $params): mixed
    {
        if (!isset($this->executors[$name])) {
            throw new \RuntimeException("No executor registered for tool '{$name}'");
        }

        return ($this->executors[$name])($params);
    }

    public function hasExecutor(string $name): bool
    {
        return isset($this->executors[$name]);
    }

    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    public function getAll(): array
    {
        return array_values($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
}

