<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Immutable classification of every exposed tool. API tools rely on Joomla's
 * Web Services ACL through the principal's API token. Direct and mixed tools
 * need an explicit local Joomla ACL check before their executor can run.
 */
final class ToolAccessPolicy
{
    public const API = 'api';
    public const DIRECT = 'direct/mixed';

    /** @var array<string, array{kind:string, component:?string, action:?string, asset?:string, id?:string, resolve?:string}> */
    private const CATALOG = [
        'get_article_by_id' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article'],
        'search_articles' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.manage'],
        'create_article' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_article' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_article' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_article_versions' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article'],
        'get_article_version' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'version_id', 'resolve' => 'article_version'],
        'diff_article_versions' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article'],
        'keep_article_version' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'version_id', 'resolve' => 'article_version'],
        'delete_article_version' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'version_id', 'resolve' => 'article_version'],
        'restore_article_version' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article'],
        'create_custom_module' => ['kind' => self::DIRECT, 'component' => 'com_modules', 'action' => 'core.create'],
        'list_custom_modules' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_custom_module_by_id' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_custom_module' => ['kind' => self::DIRECT, 'component' => 'com_modules', 'action' => 'core.edit', 'asset' => 'module', 'id' => 'id', 'resolve' => 'module'],
        'list_menus' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_menu_items' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_modules' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_module_by_id' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_module' => ['kind' => self::DIRECT, 'component' => 'com_modules', 'action' => 'core.edit', 'asset' => 'module', 'id' => 'id', 'resolve' => 'module'],
        'get_menu_item' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_menu_item' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_menu_item' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_media' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_media' => ['kind' => self::API, 'component' => null, 'action' => null],
        'upload_media' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_media_folder' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_media' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_media' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_content_languages' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_content_language' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_content_language' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_content_language' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_content_language' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_installed_languages' => ['kind' => self::DIRECT, 'component' => 'com_languages', 'action' => 'core.manage'],
        'list_template_styles' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_template_style' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_template_style' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_template_style' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_template_style' => ['kind' => self::API, 'component' => null, 'action' => null],
        'install_extension' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'list_installed_templates' => ['kind' => self::DIRECT, 'component' => 'com_templates', 'action' => 'core.manage'],
        'list_template_files' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'get_template_file' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'update_template_file' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'create_template_override' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'list_article_associations' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.manage', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article'],
        'set_article_associations' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.edit', 'asset' => 'article', 'id' => 'id', 'resolve' => 'article', 'associatedIds' => 'associated_ids'],
        'list_menu_item_associations' => ['kind' => self::DIRECT, 'component' => 'com_menus', 'action' => 'core.manage', 'asset' => 'item', 'id' => 'id', 'resolve' => 'menu_item'],
        'set_menu_item_associations' => ['kind' => self::DIRECT, 'component' => 'com_menus', 'action' => null, 'deny' => true],
        'list_categories' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_category' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_category' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_category' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_category' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_tags' => ['kind' => self::API, 'component' => null, 'action' => null],
        'get_tag' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_tag' => ['kind' => self::API, 'component' => null, 'action' => null],
        'update_tag' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_tag' => ['kind' => self::API, 'component' => null, 'action' => null],
        'list_extensions' => ['kind' => self::DIRECT, 'component' => 'com_installer', 'action' => 'core.manage'],
        'set_extension_state' => ['kind' => self::DIRECT, 'component' => 'com_plugins', 'action' => 'core.edit.state', 'id' => 'extension_id', 'resolve' => 'plugin_extension'],
        'uninstall_extension' => ['kind' => self::DIRECT, 'component' => null, 'action' => 'core.admin'],
        'create_menu' => ['kind' => self::API, 'component' => null, 'action' => null],
        'delete_menu_item' => ['kind' => self::API, 'component' => null, 'action' => null],
        'create_module' => ['kind' => self::DIRECT, 'component' => 'com_modules', 'action' => 'core.create'],
        'delete_module' => ['kind' => self::DIRECT, 'component' => 'com_modules', 'action' => 'core.delete', 'asset' => 'module', 'id' => 'id', 'resolve' => 'module'],
        'clear_cache' => ['kind' => self::DIRECT, 'component' => 'com_cache', 'action' => 'core.manage'],
        'get_rendered_page' => ['kind' => self::DIRECT, 'component' => null, 'action' => null, 'special' => 'rendered_page'],
        'seo_audit_articles' => ['kind' => self::API, 'component' => null, 'action' => null],
        'check_internal_links' => ['kind' => self::DIRECT, 'component' => 'com_content', 'action' => 'core.manage'],
    ];

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return array{kind:string, component:?string, action:?string, asset?:string, id?:string, resolve?:string, associatedIds?:string, deny?:bool, special?:string}|null */
    public function forTool(string $toolName): ?array
    {
        return self::CATALOG[$toolName] ?? null;
    }
}
