<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRecord;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedAuthService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernedCredentialAuthenticator;
use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use Joomla\Component\Mcpserver\Administrator\Service\McpCredential;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServer;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthTokenAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * An OAuth access token authenticates as the Governed Mode credential its
 * grant is bound to, and only while that credential would itself authenticate.
 */
final class OAuthGovernedAuthTest extends TestCase
{
    private const USER = 42;
    private const CREDENTIAL = 7;
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private InMemoryOAuthStore $oauthStore;
    private GovernedAuthServiceFakeStore $credentialStore;
    private CredentialCipher $cipher;
    private string $selector;

    protected function setUp(): void
    {
        $this->cipher = new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $issued = McpCredential::issue();
        $this->selector = $issued['selector'];

        $this->credentialStore = new GovernedAuthServiceFakeStore();
        $this->credentialStore->record = $this->credential('active', null);

        $this->oauthStore = new InMemoryOAuthStore();
        $this->oauthStore->addCredential(self::CREDENTIAL, self::USER, $this->selector);
    }

    private function credential(string $status, ?DateTimeImmutable $revoked): CredentialRecord
    {
        return new CredentialRecord(
            self::CREDENTIAL,
            $this->selector,
            self::USER,
            'Laptop',
            password_hash('unused', PASSWORD_DEFAULT),
            $this->cipher->encrypt('joomla-api-token'),
            $status,
            null,
            $revoked
        );
    }

    private function accessToken(): string
    {
        $server = new OAuthServer(
            $this->oauthStore,
            new OAuthConfig('https://example.com/'),
            static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
        $client = $server->register(['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');
        $request = $server->validateAuthorizationRequest([
            'client_id' => $client['client_id'],
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($server->approve($request, self::USER, self::CREDENTIAL), PHP_URL_QUERY), $query);

        return $server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'code_verifier' => self::VERIFIER,
        ], '')['access_token'];
    }

    private const SITE_ENDPOINT = 'https://example.com/index.php?option=com_mcpserver&task=rpc.handle';

    private function authenticate(string $header, bool $oauthEnabled = true, string $requestUrl = self::SITE_ENDPOINT): AuthenticatedPrincipal|array
    {
        $service = new GovernedAuthService(
            new GovernedCredentialAuthenticator($this->credentialStore, $this->cipher),
            FakeServerInput::withAuthorization($header),
            $oauthEnabled ? new OAuthTokenAuthenticator($this->oauthStore, new OAuthConfig('https://example.com/'), $requestUrl) : null
        );

        return $service->authenticate();
    }

    public function testAccessTokenActsAsTheBoundCredential(): void
    {
        $result = $this->authenticate('Bearer ' . $this->accessToken());

        $this->assertInstanceOf(AuthenticatedPrincipal::class, $result);
        $this->assertSame(self::CREDENTIAL, $result->credentialId);
        $this->assertSame(self::USER, $result->userId);
        $this->assertSame('joomla-api-token', $result->apiToken());
        $this->assertSame(1, $this->credentialStore->touches, 'last_used is stamped as for a credential bearer token');
    }

    public function testAccessTokenWorksAtTheSefFormOfTheSiteEndpoint(): void
    {
        $result = $this->authenticate('Bearer ' . $this->accessToken(), true, 'https://example.com/component/mcpserver/rpc.handle');

        $this->assertInstanceOf(AuthenticatedPrincipal::class, $result);
    }

    public function testAccessTokenIsRefusedAtTheAdministratorEndpoint(): void
    {
        $result = $this->authenticate(
            'Bearer ' . $this->accessToken(),
            true,
            'https://example.com/administrator/index.php?option=com_mcpserver&task=rpc.handle'
        );

        $this->assertSame(['error' => 'Invalid or expired OAuth access token', 'code' => JsonRpc::UNAUTHORIZED], $result);
    }

    public function testRevokingTheCredentialEndsOAuthAccessImmediately(): void
    {
        $token = $this->accessToken();
        $this->credentialStore->record = $this->credential('revoked', new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $result = $this->authenticate('Bearer ' . $token);

        $this->assertSame(['error' => 'Invalid or expired OAuth access token', 'code' => JsonRpc::UNAUTHORIZED], $result);
    }

    public function testAccessTokensAreRefusedWhileOAuthIsSwitchedOff(): void
    {
        $result = $this->authenticate('Bearer ' . $this->accessToken(), false);

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
    }

    public function testUnknownAccessTokenIsRefused(): void
    {
        $result = $this->authenticate('Bearer ' . OAuthTokenAuthenticator::PREFIX . str_repeat('A', 43));

        $this->assertSame(['error' => 'Invalid or expired OAuth access token', 'code' => JsonRpc::UNAUTHORIZED], $result);
    }

    public function testGrantWhoseCredentialChangedOwnerIsRefused(): void
    {
        $token = $this->accessToken();
        $this->credentialStore->record = new CredentialRecord(
            self::CREDENTIAL,
            $this->selector,
            self::USER + 1,
            'Laptop',
            password_hash('unused', PASSWORD_DEFAULT),
            $this->cipher->encrypt('joomla-api-token'),
            'active'
        );

        $result = $this->authenticate('Bearer ' . $token);

        $this->assertIsArray($result);
        $this->assertSame(JsonRpc::UNAUTHORIZED, $result['code']);
    }
}
