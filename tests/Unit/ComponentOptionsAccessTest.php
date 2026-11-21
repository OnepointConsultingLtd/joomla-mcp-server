<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ComponentOptionsAccess;
use PHPUnit\Framework\TestCase;

/**
 * Who may open the component's Options: the same test com_config applies.
 * CredentialsToolbarTest checks the Credentials page uses it for its Options
 * button.
 */
final class ComponentOptionsAccessTest extends TestCase
{
    public function testUserGrantedConfigureOptionsMayOpenOptions(): void
    {
        $this->assertTrue(ComponentOptionsAccess::allows(FakeUser::granted('core.options', 'mcpserver.credential.self')));
    }

    public function testUserGrantedConfigureAclAndOptionsMayOpenOptions(): void
    {
        // Site-wide core.admin (Super Users) inherits to the component, so this
        // is also the existing behaviour for them.
        $this->assertTrue(ComponentOptionsAccess::allows(FakeUser::granted('core.admin')));
    }

    public function testManageOwnCredentialsAloneDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(FakeUser::granted('mcpserver.credential.self')));
    }

    public function testAccessAdministrationInterfaceAloneDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(FakeUser::granted('core.manage', 'mcpserver.credential.audit')));
    }

    public function testNoUserDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(null));
    }
}
