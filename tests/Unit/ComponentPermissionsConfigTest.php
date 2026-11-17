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

    public function testEveryDeclaredActionIsInTheComponentSection(): void
    {
        $access = simplexml_load_file(__DIR__ . '/../../admin/access.xml');
        $this->assertNotFalse($access);

        $actions = array_map(
            static fn (\SimpleXMLElement $action): string => (string) $action['name'],
            $access->xpath('//section[@name="component"]/action')
        );

        $this->assertContains('mcpserver.credential.self', $actions);
        $this->assertContains('mcpserver.credential.audit', $actions);
    }
}
