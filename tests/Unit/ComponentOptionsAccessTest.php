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
 * The gate on the Credentials page's Options button: it must match what
 * com_config itself allows, so the button is shown to exactly the users who
 * can open the component Options.
 */
final class ComponentOptionsAccessTest extends TestCase
{
    /**
     * @param list<string> $granted Actions the user holds on com_mcpserver.
     */
    private static function user(array $granted): object
    {
        return new class ($granted) {
            /** @param list<string> $granted */
            public function __construct(private array $granted)
            {
            }

            public function authorise(string $action, ?string $asset = null): bool
            {
                return $asset === 'com_mcpserver' && in_array($action, $this->granted, true);
            }
        };
    }

    public function testConfigureOptionsHolderMayOpenOptions(): void
    {
        $this->assertTrue(ComponentOptionsAccess::allows(self::user(['core.options', 'mcpserver.credential.self'])));
    }

    public function testConfigureAclAndOptionsHolderMayOpenOptions(): void
    {
        // Site-wide core.admin (Super Users) inherits to the component, so this
        // is also the existing behaviour for them.
        $this->assertTrue(ComponentOptionsAccess::allows(self::user(['core.admin'])));
    }

    public function testManageOwnCredentialsAloneDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(self::user(['mcpserver.credential.self'])));
    }

    public function testAccessAdministrationInterfaceAloneDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(self::user(['core.manage', 'mcpserver.credential.audit'])));
    }

    public function testNoUserDoesNotOpenOptions(): void
    {
        $this->assertFalse(ComponentOptionsAccess::allows(null));
    }

    public function testCredentialsPageGatesItsOptionsButtonOnThisCheck(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../admin/src/View/Credentials/HtmlView.php');

        $this->assertMatchesRegularExpression(
            '/if \(ComponentOptionsAccess::allows\(\$user\)\) \{\s*ToolbarHelper::preferences\(\'com_mcpserver\'\);/',
            $source,
            'The Credentials page Options button must be shown to exactly the users ComponentOptionsAccess allows.'
        );
    }
}
