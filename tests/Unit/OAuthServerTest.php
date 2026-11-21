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
use Joomla\Component\Mcpserver\Administrator\Service\CredentialClaimRefusedException;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthClientMetadataFetcher;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthError;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServer;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthTokenAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * Serves Client ID Metadata Documents from memory and counts fetches.
 */
final class FakeClientMetadataFetcher implements OAuthClientMetadataFetcher
{
    /** @var array<string,array<string,mixed>> */
    public array $documents = [];

    public int $fetches = 0;

    public function fetch(string $url): array
    {
        ++$this->fetches;
        if (!isset($this->documents[$url])) {
            throw new \RuntimeException('not found');
        }

        return $this->documents[$url];
    }
}

final class OAuthServerTest extends TestCase
{
    private const CIMD_CLIENT = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
    private const CIMD_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private FakeClientMetadataFetcher $fetcher;

    private const SITE = 'https://example.com/';
    private const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const USER = 42;
    private const CREDENTIAL = 7;

    private InMemoryOAuthStore $store;
    private DateTimeImmutable $now;
    private OAuthServer $server;

    protected function setUp(): void
    {
        $this->store = new InMemoryOAuthStore();
        $this->store->addCredential(self::CREDENTIAL, self::USER, 'selector-abcdefgh');
        $this->now = new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'));
        $this->fetcher = new FakeClientMetadataFetcher();
        // Claude's published client metadata document, as served on 2026-11-20.
        $this->fetcher->documents[self::CIMD_CLIENT] = [
            'client_id' => self::CIMD_CLIENT,
            'client_name' => 'Claude',
            'client_uri' => 'https://claude.ai',
            'redirect_uris' => [self::CIMD_REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        $this->server = $this->makeServer(new OAuthConfig(self::SITE));
    }

    private function makeServer(OAuthConfig $config, ?FakeOAuthCredentialClaims $claims = null): OAuthServer
    {
        return new OAuthServer($this->store, $config, fn (): DateTimeImmutable => $this->now, $this->fetcher, $claims);
    }

    private function authenticator(): OAuthTokenAuthenticator
    {
        return new OAuthTokenAuthenticator($this->store, new OAuthConfig(self::SITE), self::SITE . 'index.php?option=com_mcpserver&task=rpc.handle');
    }

    private static function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    private function registerPublic(array $overrides = []): array
    {
        return $this->server->register($overrides + [
            'client_name' => 'ChatGPT',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
        ]);
    }

    /** @return array<string,string> */
    private function authorizeParams(string $clientId, array $overrides = []): array
    {
        return $overrides + [
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ];
    }

    /** @return array<string,string> The query parameters of the redirect. */
    private static function redirectQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array{client:array<string,mixed>,code:string} */
    private function obtainCode(): array
    {
        $client = $this->registerPublic();
        $request = $this->server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));
        $query = self::redirectQuery($this->server->approve($request, self::USER, self::CREDENTIAL));

        return ['client' => $client, 'code' => $query['code']];
    }

    /** @return array<string,mixed> */
    private function obtainTokens(): array
    {
        ['client' => $client, 'code' => $code] = $this->obtainCode();

        return $this->server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ], '') + ['client_id' => $client['client_id']];
    }

    private function assertOAuthError(string $expected, callable $call): OAuthError
    {
        try {
            $call();
        } catch (OAuthError $e) {
            $this->assertSame($expected, $e->error, $e->description);

            return $e;
        }

        $this->fail("Expected OAuth error {$expected}");
    }

    // ---- Claiming an approved credential request at consent ----------------

    private const REQUEST = '3';

    private function serverWithClaims(): array
    {
        $claims = new FakeOAuthCredentialClaims($this->store);
        $claims->approve(self::REQUEST, self::USER, 'Claude Desktop');

        return [$this->makeServer(new OAuthConfig(self::SITE), $claims), $claims];
    }

    public function testConsentOffersTheUsersApprovedRequestsAlongsideTheirCredentials(): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $claims->approve('9', 99, 'Someone else');

        $this->assertSame([['id' => self::REQUEST, 'name' => 'Claude Desktop', 'expires' => null]], $server->claimableRequests(self::USER));
        $this->assertSame([self::CREDENTIAL], array_column($server->usableCredentials(self::USER), 'id'));
        $this->assertSame([], $this->server->claimableRequests(self::USER), 'without the claims service there is nothing to claim');
    }

    public function testApprovingWithARequestClaimsItAndSignsInAsTheNewCredential(): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $client = $this->registerPublic();
        $request = $server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        $url = $server->approveByClaiming($request, self::USER, 'ada', self::REQUEST);

        $this->assertSame([self::REQUEST], $claims->claimed);
        $query = self::redirectQuery($url);
        $keys = array_keys($query);
        sort($keys);
        $this->assertSame(['code', 'iss', 'state'], $keys, 'the client gets an authorisation code and nothing else');
        $this->assertStringNotContainsString('mcp_', $url, 'no credential bearer token reaches the client');

        $tokens = $server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ], '');
        $this->assertStringNotContainsString('mcp_', (string) json_encode($tokens));

        $record = $this->authenticator()->authenticate($tokens['access_token'], $this->now);
        $this->assertSame(100, $record->credentialId, 'the grant is bound to the credential the claim created');
        $this->assertSame([], $server->claimableRequests(self::USER), 'a claimed request is not offered again');
    }

    /** @return array<string,array{0:string}> */
    public static function requestsTheUserCannotClaim(): array
    {
        return [
            'someone else\'s' => ['9'],
            'unknown' => ['404'],
        ];
    }

    /** @dataProvider requestsTheUserCannotClaim */
    public function testOnlyTheUsersOwnClaimableRequestsCanBeClaimedAtConsent(string $requestId): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $claims->approve('9', 99, 'Someone else');
        $client = $this->registerPublic();
        $request = $server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        $this->assertOAuthError('invalid_request', fn () => $server->approveByClaiming($request, self::USER, 'ada', $requestId));

        $this->assertSame([], $claims->claimed);
        $this->assertSame([], $this->store->grants);
    }

    public function testAClaimTheCredentialServiceRefusesIsReportedWithItsReason(): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $claims->refusal = new CredentialClaimRefusedException('Your user group is not allowed to hold a Joomla API token');
        $client = $this->registerPublic();
        $request = $server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        $error = $this->assertOAuthError('invalid_request', fn () => $server->approveByClaiming($request, self::USER, 'ada', self::REQUEST));

        $this->assertStringContainsString('not allowed to hold a Joomla API token', $error->description);
        $this->assertSame([], $this->store->grants);
    }

    public function testAFaultDuringTheClaimIsNotShownAsItsReason(): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $claims->refusal = new \RuntimeException('SQLSTATE[HY000]: connection lost to db-internal-7');
        $client = $this->registerPublic();
        $request = $server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        try {
            $server->approveByClaiming($request, self::USER, 'ada', self::REQUEST);
            $this->fail('The fault should propagate');
        } catch (OAuthError $e) {
            $this->fail('A fault must not become a user-facing OAuth error: ' . $e->description);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SQLSTATE', $e->getMessage());
        }
        $this->assertSame([], $this->store->grants);
    }

    public function testAFailedGrantAfterAClaimLeavesNoUsableCredential(): void
    {
        [$server, $claims] = $this->serverWithClaims();
        $client = $this->registerPublic();
        $request = $server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));
        $this->store->failGrants = true;

        try {
            $server->approveByClaiming($request, self::USER, 'ada', self::REQUEST);
            $this->fail('The approval should have failed');
        } catch (\RuntimeException) {
        }

        $this->assertSame([100], $claims->revoked);
        $this->assertSame([self::CREDENTIAL], array_column($server->usableCredentials(self::USER), 'id'), 'the never-shown credential is not left usable');
    }

    // ---- Dynamic Client Registration ---------------------------------------

    public function testPublicClientRegistrationReturnsAClientIdAndNoSecret(): void
    {
        $client = $this->registerPublic();

        $this->assertStringStartsWith('mcpc_', $client['client_id']);
        $this->assertArrayNotHasKey('client_secret', $client);
        $this->assertSame([self::REDIRECT], $client['redirect_uris']);
        $this->assertSame('none', $client['token_endpoint_auth_method']);
    }

    public function testConfidentialClientRegistrationReturnsASecretStoredOnlyAsAHash(): void
    {
        $client = $this->registerPublic(['token_endpoint_auth_method' => 'client_secret_post']);

        $this->assertArrayHasKey('client_secret', $client);
        $stored = $this->store->clients[$client['client_id']];
        $this->assertSame(hash('sha256', $client['client_secret']), $stored->secretHash);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function refusedRedirectUris(): array
    {
        return [
            'plain http to a remote host' => ['http://evil.example/cb'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,hi'],
            'file' => ['file:///etc/passwd'],
            'fragment' => ['https://chatgpt.com/cb#frag'],
            'relative' => ['/callback'],
            'whitespace' => ['https://chatgpt.com/c b'],
            'Safari launcher' => ['x-safari-https://evil.example/cb'],
            'Chrome launcher' => ['googlechromes://evil.example/cb'],
            'Edge launcher' => ['microsoft-edge:https://evil.example/cb'],
            'Android intent' => ['intent://evil.example/#Intent;end'],
        ];
    }

    /**
     * @dataProvider refusedRedirectUris
     */
    public function testRegistrationRefusesUnsafeRedirectUris(string $uri): void
    {
        $this->assertOAuthError('invalid_redirect_uri', fn () => $this->registerPublic(['redirect_uris' => [$uri]]));
        $this->assertSame([], $this->store->clients);
    }

    public function testRegistrationAcceptsLoopbackAndPrivateUseSchemes(): void
    {
        $client = $this->registerPublic(['redirect_uris' => ['http://127.0.0.1:33418/callback', 'cursor://anysphere.cursor-retrieval/oauth/callback']]);

        $this->assertCount(2, $client['redirect_uris']);
    }

    public function testAllowedRedirectHostsRestrictHttpsRegistrations(): void
    {
        $this->server = $this->makeServer(new OAuthConfig(self::SITE, allowedRedirectHosts: ['chatgpt.com', '*.claude.ai']));

        $this->registerPublic();
        $this->registerPublic(['redirect_uris' => ['https://app.claude.ai/callback']]);
        $this->assertOAuthError('invalid_redirect_uri', fn () => $this->registerPublic(['redirect_uris' => ['https://evil.example/cb']]));
    }

    public function testAllowListMustNameAppSchemesButAlwaysAllowsLoopback(): void
    {
        $this->server = $this->makeServer(new OAuthConfig(self::SITE, allowedRedirectHosts: ['chatgpt.com', 'cursor:']));

        $this->registerPublic(['redirect_uris' => ['cursor://anysphere.cursor-retrieval/oauth/callback']]);
        $this->registerPublic(['redirect_uris' => ['http://127.0.0.1:33418/callback']]);
        $this->assertOAuthError('invalid_redirect_uri', fn () => $this->registerPublic(['redirect_uris' => ['vscode://vscode.github-authentication/did-authenticate']]));
    }

    public function testConsentPageShowsAppSchemeRedirectsInFull(): void
    {
        $client = $this->registerPublic(['redirect_uris' => [self::REDIRECT, 'cursor://anysphere.cursor-retrieval/oauth/callback']]);

        $https = $this->server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));
        $app = $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['redirect_uri' => 'cursor://anysphere.cursor-retrieval/oauth/callback'])
        );

        $this->assertSame('chatgpt.com', $https->redirectTarget());
        $this->assertSame('cursor://anysphere.cursor-retrieval/oauth/callback', $app->redirectTarget());
    }

    public function testRegistrationRefusesImplicitAndClientCredentialsGrants(): void
    {
        $this->assertOAuthError('invalid_client_metadata', fn () => $this->registerPublic(['grant_types' => ['client_credentials']]));
        $this->assertOAuthError('invalid_client_metadata', fn () => $this->registerPublic(['response_types' => ['token']]));
        $this->assertOAuthError('invalid_client_metadata', fn () => $this->registerPublic(['grant_types' => 'authorization_code']));
    }

    /**
     * RFC 7591 §2: the server may replace requested values. Grants it never
     * performs are dropped from the registration instead of refusing it.
     */
    public function testRegistrationDropsGrantsTheServerDoesNotPerform(): void
    {
        $client = $this->registerPublic([
            'grant_types' => ['authorization_code', 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'client_credentials'],
            'response_types' => ['code', 'token'],
        ]);

        $this->assertSame(['authorization_code', 'refresh_token'], $client['grant_types']);
        $this->assertSame(['code'], $client['response_types']);
    }

    public function testClientNameIsStrippedOfControlCharactersAndCapped(): void
    {
        $client = $this->registerPublic(['client_name' => "Evil\nApp" . str_repeat('x', 300)]);

        $this->assertStringStartsWith('Evil App', $client['client_name']);
        $this->assertSame(150, mb_strlen($client['client_name']));
    }

    // ---- Authorisation request -------------------------------------------------

    public function testUnknownClientIsShownToTheUserNotRedirected(): void
    {
        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->authorizeParams('mcpc_nope')));

        $this->assertFalse($e->isRedirectable());
    }

    public function testUnregisteredRedirectUriIsShownToTheUserNotRedirected(): void
    {
        $client = $this->registerPublic();

        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['redirect_uri' => 'https://evil.example/cb'])
        ));

        $this->assertFalse($e->isRedirectable());
    }

    public function testMissingPkceIsRedirectedBackWithState(): void
    {
        $client = $this->registerPublic();
        $params = $this->authorizeParams($client['client_id']);
        unset($params['code_challenge']);

        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($params));
        $query = self::redirectQuery($this->server->errorRedirect($e));

        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame('xyz', $query['state']);
        $this->assertSame('https://example.com', $query['iss']);
    }

    public function testPlainPkceIsRefused(): void
    {
        $client = $this->registerPublic();

        $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['code_challenge_method' => 'plain'])
        ));
    }

    public function testResourceOnAnotherSiteIsRefused(): void
    {
        $client = $this->registerPublic();

        $this->assertOAuthError('invalid_target', fn () => $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['resource' => 'https://other.example/index.php'])
        ));
    }

    public function testLoopbackRedirectMatchesOnAnyPort(): void
    {
        $client = $this->registerPublic(['redirect_uris' => ['http://127.0.0.1/callback']]);

        $request = $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['redirect_uri' => 'http://127.0.0.1:51234/callback'])
        );

        $this->assertSame('http://127.0.0.1:51234/callback', $request->redirectUri);
    }

    public function testArrayParametersAreTreatedAsMissing(): void
    {
        $client = $this->registerPublic();

        $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest(
            $this->authorizeParams($client['client_id'], ['code_challenge' => [self::challenge()]])
        ));
    }

    // ---- Consent ---------------------------------------------------------------

    public function testApprovalRedirectsWithCodeStateAndIssuer(): void
    {
        ['code' => $code] = $this->obtainCode();
        $grant = $this->store->grants[1];

        $this->assertNotEmpty($code);
        $this->assertSame(self::CREDENTIAL, $grant['credential_id']);
        $this->assertSame(self::USER, $grant['user_id']);
        $this->assertArrayHasKey(hash('sha256', $code), $this->store->codes, 'only the hash is stored');
    }

    public function testApprovalWithSomeoneElsesCredentialIsRefused(): void
    {
        $this->store->addCredential(99, 1000, 'selector-other-user');
        $client = $this->registerPublic();
        $request = $this->server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        $this->assertOAuthError('invalid_request', fn () => $this->server->approve($request, self::USER, 99));
        $this->assertSame([], $this->store->grants);
    }

    public function testDenialRedirectsWithAccessDenied(): void
    {
        $client = $this->registerPublic();
        $request = $this->server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));

        $query = self::redirectQuery($this->server->denyRedirect($request));

        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('xyz', $query['state']);
    }

    // ---- Token endpoint --------------------------------------------------------

    public function testCodeExchangeIssuesBearerAndRefreshTokens(): void
    {
        $tokens = $this->obtainTokens();

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertStringStartsWith(OAuthTokenAuthenticator::PREFIX, $tokens['access_token']);
        $this->assertStringStartsWith('mcprt_', $tokens['refresh_token']);
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertSame('mcp', $tokens['scope']);
    }

    public function testWrongPkceVerifierIsRefused(): void
    {
        ['client' => $client, 'code' => $code] = $this->obtainCode();

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'code_verifier' => str_repeat('a', 43),
        ], ''));
    }

    public function testExpiredCodeIsRefused(): void
    {
        ['client' => $client, 'code' => $code] = $this->obtainCode();
        $this->now = $this->now->modify('+301 seconds');

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'code_verifier' => self::VERIFIER,
        ], ''));
    }

    public function testCodeIssuedToAnotherClientIsRefused(): void
    {
        ['code' => $code] = $this->obtainCode();
        $other = $this->registerPublic();

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token([
            'grant_type' => 'authorization_code',
            'client_id' => $other['client_id'],
            'code' => $code,
            'code_verifier' => self::VERIFIER,
        ], ''));
    }

    public function testReusedCodeIsRefusedAndRevokesWhatTheFirstUseIssued(): void
    {
        ['client' => $client, 'code' => $code] = $this->obtainCode();
        $post = [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'code_verifier' => self::VERIFIER,
        ];
        $tokens = $this->server->token($post, '');

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token($post, ''));

        $authenticator = $this->authenticator();
        $this->expectException(\RuntimeException::class);
        $authenticator->authenticate($tokens['access_token'], $this->now);
    }

    public function testConfidentialClientMustAuthenticateWithItsSecret(): void
    {
        $client = $this->registerPublic(['token_endpoint_auth_method' => 'client_secret_basic']);
        $request = $this->server->validateAuthorizationRequest($this->authorizeParams($client['client_id']));
        $code = self::redirectQuery($this->server->approve($request, self::USER, self::CREDENTIAL))['code'];
        $post = ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER];

        $e = $this->assertOAuthError('invalid_client', fn () => $this->server->token($post + ['client_id' => $client['client_id']], ''));
        $this->assertSame(401, $e->httpStatus);

        $basic = 'Basic ' . base64_encode(urlencode($client['client_id']) . ':' . urlencode($client['client_secret']));
        $tokens = $this->server->token($post, $basic);
        $this->assertArrayHasKey('access_token', $tokens);
    }

    public function testUnknownGrantTypeIsRefused(): void
    {
        $client = $this->registerPublic();

        $this->assertOAuthError('unsupported_grant_type', fn () => $this->server->token(['grant_type' => 'password', 'client_id' => $client['client_id']], ''));
    }

    // ---- Refresh ---------------------------------------------------------------

    public function testRefreshRotatesTheRefreshToken(): void
    {
        $tokens = $this->obtainTokens();

        $next = $this->server->token(['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']], '');

        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $this->assertNotSame($tokens['access_token'], $next['access_token']);
    }

    public function testReplayedRefreshTokenRevokesTheWholeGrant(): void
    {
        $tokens = $this->obtainTokens();
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']];
        $next = $this->server->token($refresh, '');

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token($refresh, ''));

        // The legitimate holder's rotated token is dead too: the grant is compromised.
        $this->assertOAuthError('invalid_grant', fn () => $this->server->token(
            ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $next['refresh_token']],
            ''
        ));
    }

    public function testRefreshFailsOnceTheCredentialIsNoLongerUsable(): void
    {
        $tokens = $this->obtainTokens();
        $this->store->credentials[self::CREDENTIAL]['usable'] = false;

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token(
            ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']],
            ''
        ));
        $this->assertNotNull($this->store->grants[1]['revoked']);
    }

    public function testAccessTokenCannotBeUsedAsARefreshToken(): void
    {
        $tokens = $this->obtainTokens();

        $this->assertOAuthError('invalid_grant', fn () => $this->server->token(
            ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['access_token']],
            ''
        ));
    }

    // ---- Revocation and access-token authentication ----------------------------

    public function testRevokingTheRefreshTokenEndsTheGrant(): void
    {
        $tokens = $this->obtainTokens();

        $this->server->revoke(['client_id' => $tokens['client_id'], 'token' => $tokens['refresh_token']], '');

        $this->expectException(\RuntimeException::class);
        $this->authenticator()->authenticate($tokens['access_token'], $this->now);
    }

    public function testRevokingAnotherClientsTokenDoesNothing(): void
    {
        $tokens = $this->obtainTokens();
        $other = $this->registerPublic();

        $this->server->revoke(['client_id' => $other['client_id'], 'token' => $tokens['access_token']], '');

        $record = $this->authenticator()->authenticate($tokens['access_token'], $this->now);
        $this->assertSame(self::CREDENTIAL, $record->credentialId);
    }

    public function testAccessTokenAuthenticatesUntilItExpires(): void
    {
        $tokens = $this->obtainTokens();
        $authenticator = $this->authenticator();

        $record = $authenticator->authenticate($tokens['access_token'], $this->now);
        $this->assertSame('selector-abcdefgh', $record->credentialSelector);
        $this->assertSame(self::USER, $record->userId);

        $this->expectException(\RuntimeException::class);
        $authenticator->authenticate($tokens['access_token'], $this->now->modify('+3601 seconds'));
    }

    public function testRefreshTokenIsNotAnAccessToken(): void
    {
        $tokens = $this->obtainTokens();

        $this->expectException(\RuntimeException::class);
        $this->authenticator()->authenticate($tokens['refresh_token'], $this->now);
    }

    // ---- Client ID Metadata Documents ------------------------------------------

    public function testMetadataDocumentClientSignsInWithoutRegistering(): void
    {
        $request = $this->server->validateAuthorizationRequest([
            'client_id' => self::CIMD_CLIENT,
            'redirect_uri' => self::CIMD_REDIRECT,
            'response_type' => 'code',
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
        ]);
        $this->assertSame('Claude', $request->clientName);

        $code = self::redirectQuery($this->server->approve($request, self::USER, self::CREDENTIAL))['code'];
        $tokens = $this->server->token([
            'grant_type' => 'authorization_code',
            'client_id' => self::CIMD_CLIENT,
            'code' => $code,
            'code_verifier' => self::VERIFIER,
        ], '');

        $this->assertStringStartsWith(OAuthTokenAuthenticator::PREFIX, $tokens['access_token']);
    }

    /** @return array<string,string> */
    private function cimdAuthorizeParams(array $overrides = []): array
    {
        return $overrides + [
            'client_id' => self::CIMD_CLIENT,
            'redirect_uri' => self::CIMD_REDIRECT,
            'response_type' => 'code',
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
        ];
    }

    public function testMetadataDocumentMustNameItsOwnUrlAsClientId(): void
    {
        $this->fetcher->documents[self::CIMD_CLIENT]['client_id'] = 'https://evil.example/client';

        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams()));

        $this->assertFalse($e->isRedirectable());
        $this->assertArrayNotHasKey(self::CIMD_CLIENT, $this->store->clients);
    }

    public function testMetadataDocumentRedirectUrisFollowTheRedirectPolicy(): void
    {
        $this->server = $this->makeServer(new OAuthConfig(self::SITE, allowedRedirectHosts: ['chatgpt.com']));

        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams()));

        $this->assertFalse($e->isRedirectable());
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function nonPublicDocuments(): array
    {
        return [
            'shared secret method' => [['token_endpoint_auth_method' => 'client_secret_post']],
            'private key JWT' => [['token_endpoint_auth_method' => 'private_key_jwt']],
            'embedded secret' => [['client_secret' => 'oops']],
            'implicit grant' => [['response_types' => ['token']]],
            'no code grant' => [['grant_types' => ['refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer']]],
        ];
    }

    /**
     * @dataProvider nonPublicDocuments
     */
    public function testMetadataDocumentMustDescribeAPublicCodeFlowClient(array $change): void
    {
        $this->fetcher->documents[self::CIMD_CLIENT] = $change + $this->fetcher->documents[self::CIMD_CLIENT];

        $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams()));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidClientIdUrls(): array
    {
        return [
            'no path' => ['https://claude.ai'],
            'root path' => ['https://claude.ai/'],
            'credentials' => ['https://user:pw@claude.ai/client'],
            'fragment' => ['https://claude.ai/client#x'],
            'query' => ['https://claude.ai/client?x=1'],
            'dot segment' => ['https://claude.ai/a/../client'],
            'too long for storage' => ['https://claude.ai/' . str_repeat('a', 200)],
        ];
    }

    /**
     * @dataProvider invalidClientIdUrls
     */
    public function testInvalidClientIdUrlIsRefusedWithoutBeingFetched(string $url): void
    {
        $this->fetcher->documents[$url] = ['client_id' => $url] + $this->fetcher->documents[self::CIMD_CLIENT];

        $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams(['client_id' => $url])));
        $this->assertSame(0, $this->fetcher->fetches);
    }

    public function testMetadataDocumentIsCachedForAnHourThenRefetched(): void
    {
        $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams());
        $this->now = $this->now->modify('+59 minutes');
        $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams());
        $this->assertSame(1, $this->fetcher->fetches);

        $this->fetcher->documents[self::CIMD_CLIENT]['client_name'] = 'Claude (renamed)';
        $this->now = $this->now->modify('+2 minutes');
        $request = $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams());

        $this->assertSame(2, $this->fetcher->fetches);
        $this->assertSame('Claude (renamed)', $request->clientName);
    }

    public function testUnreachableMetadataDocumentIsShownToTheUser(): void
    {
        unset($this->fetcher->documents[self::CIMD_CLIENT]);

        $e = $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams()));

        $this->assertFalse($e->isRedirectable());
    }

    public function testMetadataDocumentClientHasAVerifiedHostAndASanitisedName(): void
    {
        $this->fetcher->documents[self::CIMD_CLIENT]['client_name'] = "Claude\n" . str_repeat('x', 300);
        $dcr = $this->registerPublic();

        $cimd = $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams());
        $registered = $this->server->validateAuthorizationRequest($this->authorizeParams($dcr['client_id']));

        $this->assertSame('claude.ai', $cimd->verifiedClientHost());
        $this->assertStringStartsWith('Claude x', $cimd->clientName);
        $this->assertSame(150, mb_strlen($cimd->clientName));
        $this->assertNull($registered->verifiedClientHost());
    }

    public function testMetadataDocumentsAreNotFetchedWhileClientRegistrationIsOff(): void
    {
        $this->server = $this->makeServer(new OAuthConfig(self::SITE, registrationEnabled: false));

        $this->assertOAuthError('invalid_request', fn () => $this->server->validateAuthorizationRequest($this->cimdAuthorizeParams()));
        $this->assertSame(0, $this->fetcher->fetches);
    }
}
