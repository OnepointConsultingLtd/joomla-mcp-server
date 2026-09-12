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
use Joomla\Component\Mcpserver\Administrator\Service\{
    AuthenticatedPrincipal,
    AuthService,
    CredentialCipher,
    CredentialRecord,
    CredentialStoreInterface,
    GovernedAuthService,
    GovernedCredentialAuthenticator,
    JsonRpc,
    McpCredential,
};
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

final class GovernedAuthServiceFakeStore implements CredentialStoreInterface
{
    public ?CredentialRecord $record = null;

    public int $touches = 0;

    public function findBySelector(string $selector): ?CredentialRecord
    {
        return $this->record?->selector === $selector ? $this->record : null;
    }

    public function touchLastUsed(int $credentialId): void
    {
        ++$this->touches;
    }
}

final class GovernedAuthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::reset();
    }

    private function makeAuthenticator(): array
    {
        $issued = McpCredential::issue();
        $cipher = new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $store = new GovernedAuthServiceFakeStore();
        $store->record = new CredentialRecord(1, $issued['selector'], 2, 'Client', $issued['verifier'], $cipher->encrypt('api-token'), 'active');

        return [new GovernedCredentialAuthenticator($store, $cipher), $issued['token'], $store];
    }

    public function testGovernedModeReturnsPrincipalForValidHeader(): void
    {
        [$authenticator, $token] = $this->makeAuthenticator();

        $service = new GovernedAuthService($authenticator, fn (string $name, string $default): string => $name === 'HTTP_AUTHORIZATION' ? 'Bearer ' . $token : $default);

        $result = $service->authenticate();

        $this->assertInstanceOf(AuthenticatedPrincipal::class, $result);
        $this->assertSame(2, $result->userId);
        $this->assertSame('api-token', $result->joomlaApiToken);
    }

    public function testMissingAuthorizationHeaderReturnsGenericUnauthorized(): void
    {
        [$authenticator] = $this->makeAuthenticator();
        $service = new GovernedAuthService($authenticator, fn (string $name, string $default): string => $default);

        $result = $service->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
        $this->assertSame('Missing bearer token in Authorization header', $result['error']);
    }

    public function testInvalidCredentialReturnsGenericUnauthorizedWithoutLeakingDetail(): void
    {
        $cipher = new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $store = new GovernedAuthServiceFakeStore();
        $authenticator = new GovernedCredentialAuthenticator($store, $cipher);
        $unknownToken = McpCredential::issue()['token'];

        $service = new GovernedAuthService($authenticator, fn (string $name, string $default): string => $name === 'HTTP_AUTHORIZATION' ? 'Bearer ' . $unknownToken : $default);

        $result = $service->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
        $this->assertSame('Invalid or expired MCP credential', $result['error']);
    }

    public function testAuthServiceGovernedModeDoesNotUseSharedToken(): void
    {
        [$authenticator, $token, $store] = $this->makeAuthenticator();
        $governed = new GovernedAuthService($authenticator, fn (string $name, string $default): string => $name === 'HTTP_AUTHORIZATION' ? 'Bearer ' . $token : $default);

        $config = new Registry([
            'governed_mode' => true,
            // A shared token is present but must never be consulted in governed mode.
            'mcp_bearer_token' => 'legacy-shared-token',
            'require_auth' => true,
        ]);

        $authService = new AuthService($config, $governed);

        $result = $authService->authenticatePrincipal();

        $this->assertInstanceOf(AuthenticatedPrincipal::class, $result);
        $this->assertSame(2, $result->userId);
        $this->assertNull($authService->authenticate());
        // Memoized: RpcHandlerTrait calls authenticatePrincipal() then, on a
        // null principal, authenticate() for the same request. Both calls
        // above must observe a single underlying authentication attempt, so
        // touchLastUsed() (and any other authentication side effect) fires
        // exactly once per request rather than once per call.
        $this->assertSame(1, $store->touches, 'authenticate() must reuse the memoized result from authenticatePrincipal() rather than re-authenticating');
    }

    public function testAuthServiceMemoizesResolutionAcrossRepeatedCallsInEitherOrder(): void
    {
        [$authenticator, $token, $store] = $this->makeAuthenticator();
        $governed = new GovernedAuthService($authenticator, fn (string $name, string $default): string => $name === 'HTTP_AUTHORIZATION' ? 'Bearer ' . $token : $default);

        $config = new Registry(['governed_mode' => true]);
        $authService = new AuthService($config, $governed);

        // Call authenticatePrincipal() twice and authenticate() twice, in any
        // order: the underlying credential verification (and its
        // touchLastUsed side effect) must run only once for this AuthService
        // instance's lifetime, since one instance corresponds to one request.
        $authService->authenticatePrincipal();
        $authService->authenticate();
        $authService->authenticatePrincipal();
        $authService->authenticate();

        $this->assertSame(1, $store->touches);
    }

    public function testAuthServiceMemoizesFailedGovernedAuthenticationAttempts(): void
    {
        $cipher = new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $store = new GovernedAuthServiceFakeStore();
        $authenticator = new GovernedCredentialAuthenticator($store, $cipher);
        $unknownToken = McpCredential::issue()['token'];

        $callCount = 0;
        $governed = new GovernedAuthService($authenticator, function (string $name, string $default) use (&$callCount, $unknownToken): string {
            if ($name === 'HTTP_AUTHORIZATION') {
                ++$callCount;
            }

            return $name === 'HTTP_AUTHORIZATION' ? 'Bearer ' . $unknownToken : $default;
        });

        $config = new Registry(['governed_mode' => true]);
        $authService = new AuthService($config, $governed);

        $first = $authService->authenticate();
        $second = $authService->authenticate();

        $this->assertSame($first, $second, 'a repeated authenticate() call must return the same memoized failure, not re-run verification');
        $this->assertSame(1, $callCount, 'the Authorization header must only be read once per request');
    }

    public function testAuthServiceGovernedModeWithoutHelperIsForbidden(): void
    {
        $config = new Registry(['governed_mode' => true]);
        $authService = new AuthService($config, null);

        $result = $authService->authenticate();

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::FORBIDDEN, $result['code']);
    }

    public function testLegacyModeUnaffectedByGovernedHelperAbsence(): void
    {
        Factory::$application = new class {
            public object $input;

            public function __construct()
            {
                $this->input = new class {
                    public object $server;

                    public function __construct()
                    {
                        $this->server = new class {
                            public function getString(string $name, string $default = ''): string
                            {
                                return $default;
                            }
                        };
                    }
                };
            }
        };

        $config = new Registry([
            'governed_mode' => false,
            'require_auth' => false,
        ]);
        $authService = new AuthService($config, null);

        $this->assertNull($authService->authenticate());
        $this->assertNull($authService->authenticatePrincipal());
    }
}
