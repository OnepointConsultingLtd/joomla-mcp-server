<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\AuthService;
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the legacy shared-token path and the IP allow-list.
 *
 * Both were restructured when governed mode landed — authenticate() was split
 * into resolve()/doResolve()/authenticateLegacy() and the allow-list check moved
 * up into doResolve() so it now applies to governed mode too — but neither had
 * any test. Deleting the hash_equals() comparison or the allow-list block left
 * the whole suite green.
 */
final class LegacyAuthServiceTest extends TestCase
{
    /**
     * Install a fake application whose $input->server returns the given values.
     *
     * @param array<string,string> $server
     */
    private function installApp(array $server): void
    {
        Factory::reset();
        Factory::$application = new class ($server) {
            public object $input;

            public function __construct(array $server)
            {
                $this->input = new class ($server) {
                    public object $server;

                    public function __construct(array $values)
                    {
                        $this->server = new class ($values) {
                            /** @param array<string,string> $values */
                            public function __construct(private array $values)
                            {
                            }

                            public function getString(string $name, string $default = ''): string
                            {
                                return $this->values[$name] ?? $default;
                            }
                        };
                    }
                };
            }

            public function getName(): string
            {
                return 'site';
            }
        };
    }

    private function service(array $config, array $server = []): AuthService
    {
        $this->installApp($server);

        return new AuthService(new Registry($config), null);
    }

    public function testValidSharedTokenAuthenticates(): void
    {
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => 'correct-token'],
            ['HTTP_AUTHORIZATION' => 'Bearer correct-token']
        );

        $this->assertNull($auth->authenticate(), 'a matching shared token must authenticate');
    }

    /**
     * Without this, deleting the hash_equals() comparison lets any bearer token
     * authenticate in legacy mode and no test notices.
     */
    public function testWrongSharedTokenIsRejected(): void
    {
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => 'correct-token'],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
    }

    public function testTokenThatIsAPrefixOfTheRealOneIsRejected(): void
    {
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => 'correct-token'],
            ['HTTP_AUTHORIZATION' => 'Bearer correct']
        );

        $this->assertIsArray($auth->authenticate());
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => 'correct-token']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
    }

    public function testRedirectAuthorizationHeaderIsAccepted(): void
    {
        // Apache CGI moves the header to REDIRECT_HTTP_AUTHORIZATION.
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => 'correct-token'],
            ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer correct-token']
        );

        $this->assertNull($auth->authenticate());
    }

    /**
     * Joomla only persists config.xml defaults once an admin saves Options, so
     * an unsaved install yields an empty registry. require_auth must default to
     * true there or the public endpoint ships unauthenticated.
     */
    public function testUnsavedConfigStillRequiresAuth(): void
    {
        $auth = $this->service(['mcp_bearer_token' => 'correct-token']);

        $result = $auth->authenticate();

        $this->assertIsArray($result, 'require_auth must default to true on a fresh install');
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
    }

    public function testAuthRequiredButNoServerTokenConfiguredIsForbidden(): void
    {
        $auth = $this->service(
            ['governed_mode' => false, 'require_auth' => true, 'mcp_bearer_token' => ''],
            ['HTTP_AUTHORIZATION' => 'Bearer anything']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::FORBIDDEN, $result['code']);
    }

    public function testIpAllowListRejectsAnUnlistedAddress(): void
    {
        $auth = $this->service(
            [
                'governed_mode' => false,
                'require_auth' => false,
                'ip_allow_list' => '203.0.113.5, 203.0.113.6',
            ],
            ['REMOTE_ADDR' => '198.51.100.9']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::FORBIDDEN, $result['code']);
    }

    public function testIpAllowListAdmitsAListedAddress(): void
    {
        $auth = $this->service(
            [
                'governed_mode' => false,
                'require_auth' => false,
                'ip_allow_list' => '203.0.113.5, 203.0.113.6',
            ],
            ['REMOTE_ADDR' => '203.0.113.6']
        );

        $this->assertNull($auth->authenticate());
    }

    public function testForwardedForIsIgnoredFromAnUntrustedPeer(): void
    {
        // Otherwise the allow-list is bypassable with a spoofed header.
        $auth = $this->service(
            [
                'governed_mode' => false,
                'require_auth' => false,
                'ip_allow_list' => '203.0.113.5',
            ],
            ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result, 'X-Forwarded-For must not be trusted from an unlisted peer');
        $this->assertSame(JsonRpc::FORBIDDEN, $result['code']);
    }

    public function testForwardedForIsHonouredFromAConfiguredTrustedProxy(): void
    {
        $auth = $this->service(
            [
                'governed_mode' => false,
                'require_auth' => false,
                'ip_allow_list' => '203.0.113.5',
                'trusted_proxies' => '198.51.100.9',
            ],
            ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.1']
        );

        $this->assertNull($auth->authenticate());
    }

    public function testIpAllowListAppliesToGovernedModeToo(): void
    {
        // The check moved into doResolve() so it guards both modes; a governed
        // request from an unlisted IP must be refused before authentication.
        $auth = $this->service(
            ['governed_mode' => true, 'ip_allow_list' => '203.0.113.5'],
            ['REMOTE_ADDR' => '198.51.100.9']
        );

        $result = $auth->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::FORBIDDEN, $result['code']);
        $this->assertSame('IP not allowed', $result['error']);
    }
}
