<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthEndpoints;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthRedirectUriPolicy;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

final class OAuthConfigTest extends TestCase
{
    public function testOAuthRequiresGovernedMode(): void
    {
        $this->assertFalse(OAuthConfig::isEnabled(new Registry(['oauth_enabled' => 1])));
        $this->assertFalse(OAuthConfig::isEnabled(new Registry(['governed_mode' => 1])));
        $this->assertTrue(OAuthConfig::isEnabled(new Registry(['governed_mode' => 1, 'oauth_enabled' => 1])));
    }

    public function testParamsAreReadWithFloorsAndHostList(): void
    {
        $config = OAuthConfig::fromParams(new Registry([
            'oauth_access_token_ttl' => 5,
            'oauth_refresh_token_ttl_days' => 2,
            'oauth_allowed_redirect_hosts' => "ChatGPT.com,\n *.claude.ai \n",
        ]), 'https://example.com/', true);

        $this->assertSame(300, $config->accessTokenTtl);
        $this->assertSame(2 * 86400, $config->refreshTokenTtl);
        $this->assertSame(['chatgpt.com', '*.claude.ai'], $config->allowedRedirectHosts);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function resources(): array
    {
        return [
            'component endpoint' => ['https://example.com/index.php?option=com_mcpserver&task=rpc.handle', true],
            'SEF endpoint' => ['https://example.com/component/mcpserver/rpc.handle', true],
            'explicit default port' => ['https://example.com:443/index.php', true],
            'other host' => ['https://evil.example/index.php', false],
            'other scheme' => ['http://example.com/index.php', false],
            'other port' => ['https://example.com:8443/index.php', false],
            'fragment' => ['https://example.com/index.php#x', false],
            'lookalike host' => ['https://example.com.evil.example/', false],
            'administrator endpoint' => ['https://example.com/administrator/index.php?option=com_mcpserver&task=rpc.handle', false],
            'administrator, other case' => ['https://example.com/Administrator/index.php', false],
        ];
    }

    /**
     * @dataProvider resources
     */
    public function testOwnsOnlyResourcesOnThisSite(string $uri, bool $owned): void
    {
        $this->assertSame($owned, (new OAuthConfig('https://example.com/'))->ownsResource($uri));
    }

    public function testResourceMustSitUnderASubdirectorySite(): void
    {
        $config = new OAuthConfig('https://example.com/joomla/');

        $this->assertTrue($config->ownsResource('https://example.com/joomla/index.php'));
        $this->assertFalse($config->ownsResource('https://example.com/other/index.php'));
        $this->assertFalse($config->ownsResource('https://example.com/joomlax/index.php'));
        $this->assertSame('https://example.com/joomla', $config->issuer());
    }

    /**
     * @return array<string,array{0:string,1:string,2:array{0:string,1:string}|null}>
     */
    public static function paths(): array
    {
        return [
            'AS metadata' => ['/.well-known/oauth-authorization-server', '', [OAuthEndpoints::AUTHORIZATION_SERVER, '']],
            'PRM with resource path' => ['/.well-known/oauth-protected-resource/index.php', '', [OAuthEndpoints::PROTECTED_RESOURCE, 'index.php']],
            'token' => ['/mcp-oauth/token', '', [OAuthEndpoints::TOKEN, '']],
            'authorize via PATH_INFO' => ['/index.php/mcp-oauth/authorize', '', ['authorize', '']],
            'subdirectory site' => ['/joomla/mcp-oauth/register', '/joomla', [OAuthEndpoints::REGISTER, '']],
            'trailing slash' => ['/mcp-oauth/revoke/', '', [OAuthEndpoints::REVOKE, '']],
            'unknown endpoint' => ['/mcp-oauth/userinfo', '', null],
            'ordinary page' => ['/blog/my-article', '', null],
            'lookalike well-known' => ['/.well-known/oauth-authorization-server-evil', '', null],
            'acme challenge' => ['/.well-known/acme-challenge/abc', '', null],
        ];
    }

    /**
     * @dataProvider paths
     */
    public function testPluginRoutesOnlyItsOwnPaths(string $path, string $root, ?array $expected): void
    {
        $this->assertSame($expected, OAuthConfig::routeForPath($path, $root));
    }

    /**
     * Clients that build /.well-known/oauth-protected-resource/<path> from the
     * MCP endpoint keep only its path, so the query string that makes it the
     * MCP endpoint has to be restored.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function wellKnownResourcePaths(): array
    {
        $endpoint = 'https://example.com/index.php?option=com_mcpserver&task=rpc.handle';
        $subdirEndpoint = 'https://example.com/joomla/index.php?option=com_mcpserver&task=rpc.handle';

        return [
            'URL rewriting' => ['/.well-known/oauth-protected-resource/index.php', '', 'https://example.com/', $endpoint],
            'no URL rewriting (PATH_INFO)' => ['/index.php/.well-known/oauth-protected-resource/index.php', '', 'https://example.com/', $endpoint],
            'subdirectory site, URL rewriting' => ['/joomla/.well-known/oauth-protected-resource/joomla/index.php', '/joomla', 'https://example.com/joomla/', $subdirEndpoint],
            'subdirectory site, no URL rewriting' => ['/joomla/index.php/.well-known/oauth-protected-resource/joomla/index.php', '/joomla', 'https://example.com/joomla/', $subdirEndpoint],
            'well-known at the web root, subdirectory site' => ['/.well-known/oauth-protected-resource/joomla/index.php', '/joomla', 'https://example.com/joomla/', $subdirEndpoint],
            'endpoint at the subdirectory itself' => ['/joomla/.well-known/oauth-protected-resource/joomla/', '/joomla', 'https://example.com/joomla/', 'https://example.com/joomla/?option=com_mcpserver&task=rpc.handle'],
            'any other path is named as asked' => ['/.well-known/oauth-protected-resource/component/mcpserver/rpc.handle', '', 'https://example.com/', 'https://example.com/component/mcpserver/rpc.handle'],
        ];
    }

    /**
     * @dataProvider wellKnownResourcePaths
     */
    public function testWellKnownSuffixNamesTheMcpEndpointWithItsQueryString(string $path, string $root, string $siteRoot, string $expected): void
    {
        $route = OAuthConfig::routeForPath($path, $root);
        $this->assertNotNull($route);
        [$endpoint, $suffix] = $route;
        $this->assertSame(OAuthEndpoints::PROTECTED_RESOURCE, $endpoint);

        $config = new OAuthConfig($siteRoot, str_contains($path, 'index.php/') === false);
        $resource = $config->resourceForWellKnownPath('https://example.com', $suffix);

        $this->assertSame($expected, $resource);
        $this->assertTrue($config->ownsResource($resource), 'the metadata must name the resource rather than fall back to the default');
    }

    public function testWellKnownSuffixGivesTheSameResourceAsTheResourceMetadataLink(): void
    {
        $config = new OAuthConfig('https://example.com/');
        // What the 401's resource_metadata link carries for a client connected
        // to the MCP endpoint (RpcHandlerTrait::sendOAuthChallenge()).
        $connectedTo = 'https://example.com/index.php?option=com_mcpserver&task=rpc.handle';
        parse_str((string) parse_url($config->protectedResourceMetadataUrl($connectedTo), PHP_URL_QUERY), $linkQuery);

        $this->assertSame($linkQuery['resource'], $config->resourceForWellKnownPath('https://example.com', 'index.php'));
        $this->assertSame($config->defaultResource(), $config->resourceForWellKnownPath('https://example.com/', '/index.php'));
    }

    public function testLoopbackRedirectsMatchAcrossPortsButNothingElseDoes(): void
    {
        $this->assertTrue(OAuthRedirectUriPolicy::matches('http://127.0.0.1/cb', 'http://127.0.0.1:5000/cb'));
        $this->assertTrue(OAuthRedirectUriPolicy::matches('http://localhost:1/cb', 'http://LOCALHOST:2/cb'));
        $this->assertFalse(OAuthRedirectUriPolicy::matches('http://127.0.0.1/cb', 'http://127.0.0.1:5000/other'));
        $this->assertFalse(OAuthRedirectUriPolicy::matches('https://claude.ai/cb', 'https://claude.ai:444/cb'));
        $this->assertFalse(OAuthRedirectUriPolicy::matches('https://claude.ai/cb', 'https://claude.ai/cb?x=1'));
    }
}
