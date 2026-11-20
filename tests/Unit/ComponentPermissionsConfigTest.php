<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * The ACL actions declared in access.xml (notably mcpserver.credential.self
 * and mcpserver.credential.audit) can only be assigned to user groups through
 * a `rules` field in the component Options. Without it the self-service
 * credential page is unreachable for anyone who is not given core.manage.
 */
final class ComponentPermissionsConfigTest extends TestCase
{
    public function testOptionsExposeComponentPermissions(): void
    {
        $config = simplexml_load_file(__DIR__ . '/../../admin/config.xml');
        $this->assertNotFalse($config);

        $fields = $config->xpath('//fieldset[@name="permissions"]/field[@name="rules"][@type="rules"]');
        $this->assertCount(1, $fields);

        $field = $fields[0];
        $this->assertSame('com_mcpserver', (string) $field['component']);
        $this->assertSame('component', (string) $field['section']);
        $this->assertSame('rules', (string) $field['filter']);
        $this->assertSame('rules', (string) $field['validate']);
    }

    public function testComponentSectionDeclaresTheGrantableActions(): void
    {
        $actions = array_map(
            static fn (\SimpleXMLElement $action): string => (string) $action['name'],
            $this->componentActions()
        );

        $this->assertSame(
            ['core.admin', 'core.options', 'core.manage', 'mcpserver.credential.self', 'mcpserver.credential.audit'],
            $actions
        );
    }

    /**
     * The Permissions tab prints every action's title and description through
     * Text::_(), so an undefined key shows up as raw text. Component keys must
     * be defined in the component's language file; Joomla core keys (which
     * cannot be checked here) are limited to an allow-list of ones known to
     * exist in Joomla 4 and 5. Core actions get no description, as in core
     * components.
     */
    public function testEveryActionLanguageKeyResolves(): void
    {
        $strings = parse_ini_file(__DIR__ . '/../../admin/language/en-GB/en-GB.com_mcpserver.ini');
        $this->assertIsArray($strings);

        $coreKeys = ['JACTION_ADMIN', 'JACTION_OPTIONS', 'JACTION_MANAGE'];

        foreach ($this->componentActions() as $action) {
            $name = (string) $action['name'];

            foreach (['title', 'description'] as $attribute) {
                if (!isset($action[$attribute])) {
                    continue;
                }

                $key = (string) $action[$attribute];

                if (str_starts_with($key, 'COM_MCPSERVER_')) {
                    $this->assertArrayHasKey($key, $strings, "{$name} {$attribute} key {$key} is not defined");
                } else {
                    $this->assertSame('title', $attribute, "{$name} has a core-language description {$key}");
                    $this->assertContains($key, $coreKeys, "{$name} uses unknown core key {$key}");
                }
            }
        }
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function componentActions(): array
    {
        $access = simplexml_load_file(__DIR__ . '/../../admin/access.xml');
        $this->assertNotFalse($access);

        return $access->xpath('//section[@name="component"]/action');
    }
}
