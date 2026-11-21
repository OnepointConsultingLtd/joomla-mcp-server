<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ComponentTaskUrl;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The URLs of the component's front-end tasks, above all the MCP endpoint,
 * which MCP clients connect to and OAuth access tokens are bound to. Every
 * part of the component that hands one out builds it here, so they can't
 * drift apart.
 */
final class ComponentTaskUrlTest extends TestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function siteRoots(): array
    {
        return [
            'root with trailing slash' => ['https://example.com/', 'https://example.com/index.php?option=com_mcpserver&task=rpc.handle'],
            'root without trailing slash' => ['https://example.com', 'https://example.com/index.php?option=com_mcpserver&task=rpc.handle'],
            'subdirectory site' => ['https://example.com/joomla/', 'https://example.com/joomla/index.php?option=com_mcpserver&task=rpc.handle'],
            'non-default port' => ['http://localhost:8080', 'http://localhost:8080/index.php?option=com_mcpserver&task=rpc.handle'],
        ];
    }

    #[DataProvider('siteRoots')]
    public function testTheMcpEndpointIsTheSitesFrontControllerRunningTheRpcTask(string $siteRoot, string $expected): void
    {
        $this->assertSame($expected, ComponentTaskUrl::mcpEndpoint($siteRoot));
    }

    public function testAnyTaskRunsThroughTheSameFrontController(): void
    {
        $this->assertSame(
            'https://example.com/joomla/index.php?option=com_mcpserver&task=oauth.resource',
            ComponentTaskUrl::url('https://example.com/joomla', 'oauth.resource')
        );
    }

    public function testOAuthBindsTokensToTheMcpEndpointByDefault(): void
    {
        $config = new OAuthConfig('https://example.com/joomla/');

        $this->assertSame(ComponentTaskUrl::mcpEndpoint('https://example.com/joomla/'), $config->defaultResource());
        $this->assertSame(ComponentTaskUrl::url('https://example.com/joomla/', 'oauth.resource'), $config->componentUrl('oauth.resource'));
    }
}
