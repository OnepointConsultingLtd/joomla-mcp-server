<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

final class GovernedManualTest extends TestCase
{
    public function testLandingViewSelectsManualFromGovernedMode(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../admin/src/View/Mcpcomponent/HtmlView.php');

        $this->assertStringContainsString("get('governed_mode', 0)", $source);
        $this->assertStringContainsString('Bearer <YOUR_GOVERNED_CREDENTIAL>', $source);
        $this->assertStringContainsString("'token' => \$this->governedMode ? '' : \$token", $source);
    }

    public function testGovernedManualLinksToCredentialsAndDoesNotRevealLegacyToken(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../admin/tmpl/mcpcomponent/default.php');

        $this->assertStringContainsString('COM_MCPSERVER_MANUAL_GOVERNED_TITLE', $source);
        $this->assertStringContainsString('task=credentials.display', $source);
        $this->assertMatchesRegularExpression('/if \(\$this->governedMode\).*?elseif \(\$this->mcpConfig\[\'token\'\]\)/s', $source);
    }
}
