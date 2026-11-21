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
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthEndpoints;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthHttpRequest;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServer;
use Joomla\Component\Mcpserver\Administrator\Service\RateLimiter;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use PHPUnit\Framework\TestCase;

final class OAuthEndpointsTest extends TestCase
{
    private function endpoints(?OAuthConfig $config = null, ?RateLimiter $limiter = null): OAuthEndpoints
    {
        return new OAuthEndpoints(
            new OAuthServer(
                new InMemoryOAuthStore(),
                $config ?? new OAuthConfig('https://example.com/'),
                static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
            ),
            $limiter,
            'joomla-mcp-server'
        );
    }

    private static function request(string $method, array $overrides = []): OAuthHttpRequest
    {
        return new OAuthHttpRequest(
            $method,
            $overrides['query'] ?? [],
            $overrides['post'] ?? [],
            $overrides['body'] ?? '',
            $overrides['authorization'] ?? '',
            '203.0.113.9',
        );
    }

    public function testAuthorizationServerMetadataAdvertisesCleanEndpointsAndS256(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::AUTHORIZATION_SERVER, self::request('GET'));
        $metadata = json_decode($response->body, true);

        $this->assertSame(200, $response->status);
        $this->assertSame('https://example.com', $metadata['issuer']);
        $this->assertSame('https://example.com/mcp-oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertSame('https://example.com/mcp-oauth/token', $metadata['token_endpoint']);
        $this->assertSame('https://example.com/mcp-oauth/register', $metadata['registration_endpoint']);
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        $this->assertTrue($metadata['authorization_response_iss_parameter_supported']);
        $this->assertTrue($metadata['client_id_metadata_document_supported']);
        $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
    }

    public function testEndpointsGoThroughIndexPhpWithoutUrlRewriting(): void
    {
        $response = $this->endpoints(new OAuthConfig('https://example.com/', urlRewriting: false))
            ->handle(OAuthEndpoints::AUTHORIZATION_SERVER, self::request('GET'));

        $this->assertSame('https://example.com/index.php/mcp-oauth/token', json_decode($response->body, true)['token_endpoint']);
    }

    public function testRegistrationEndpointIsNotAdvertisedOrServedWhenDisabled(): void
    {
        $endpoints = $this->endpoints(new OAuthConfig('https://example.com/', registrationEnabled: false));

        $metadata = json_decode($endpoints->handle(OAuthEndpoints::AUTHORIZATION_SERVER, self::request('GET'))->body, true);
        $this->assertArrayNotHasKey('registration_endpoint', $metadata);
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $metadata);

        $response = $endpoints->handle(OAuthEndpoints::REGISTER, self::request('POST', ['body' => '{"redirect_uris":["https://claude.ai/cb"]}']));
        $this->assertSame(403, $response->status);
    }

    public function testProtectedResourceMetadataEchoesOwnResourceAndIgnoresForeignOnes(): void
    {
        $endpoints = $this->endpoints();
        $own = 'https://example.com/component/mcpserver/rpc.handle';

        $metadata = json_decode($endpoints->handle(OAuthEndpoints::PROTECTED_RESOURCE, self::request('GET', ['query' => ['resource' => $own]]))->body, true);
        $this->assertSame($own, $metadata['resource']);
        $this->assertSame(['https://example.com'], $metadata['authorization_servers']);

        $metadata = json_decode($endpoints->handle(OAuthEndpoints::PROTECTED_RESOURCE, self::request('GET', ['query' => ['resource' => 'https://evil.example/']]))->body, true);
        $this->assertSame('https://example.com/index.php?option=com_mcpserver&task=rpc.handle', $metadata['resource']);
    }

    public function testRegistrationTakesAJsonBodyAndAnswers201(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::REGISTER, self::request('POST', [
            'body' => json_encode(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none']),
        ]));

        $this->assertSame(201, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertStringStartsWith('mcpc_', json_decode($response->body, true)['client_id']);
    }

    public function testMalformedRegistrationBodyIsAClientMetadataError(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::REGISTER, self::request('POST', ['body' => '["not","an","object"]']));

        $this->assertSame(400, $response->status);
        $this->assertSame('invalid_client_metadata', json_decode($response->body, true)['error']);
    }

    public function testTokenEndpointOnlyAcceptsPost(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::TOKEN, self::request('GET'));

        $this->assertSame(405, $response->status);
        $this->assertSame('POST, OPTIONS', $response->headers['Allow']);
    }

    public function testPreflightIsAnsweredWithCors(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::TOKEN, self::request('OPTIONS'));

        $this->assertSame(204, $response->status);
        $this->assertStringContainsString('Authorization', $response->headers['Access-Control-Allow-Headers']);
    }

    public function testFailedClientAuthenticationWithBasicCredentialsGetsAChallenge(): void
    {
        $response = $this->endpoints()->handle(OAuthEndpoints::TOKEN, self::request('POST', [
            'post' => ['grant_type' => 'authorization_code'],
            'authorization' => 'Basic ' . base64_encode('mcpc_unknown:secret'),
        ]));

        $this->assertSame(401, $response->status);
        $this->assertSame('invalid_client', json_decode($response->body, true)['error']);
        $this->assertSame('Basic realm="mcp-oauth"', $response->headers['WWW-Authenticate']);
    }

    public function testRegistrationIsRateLimitedPerIpButTheTokenEndpointIsNot(): void
    {
        $endpoints = $this->endpoints(null, new RateLimiter(new SimpleArrayCache(), 1, 60));
        $register = self::request('POST', ['body' => '{"redirect_uris":["https://claude.ai/cb"],"token_endpoint_auth_method":"none"}']);
        $token = self::request('POST', ['post' => ['grant_type' => 'authorization_code']]);

        $this->assertSame(201, $endpoints->handle(OAuthEndpoints::REGISTER, $register)->status);
        $limited = $endpoints->handle(OAuthEndpoints::REGISTER, $register);
        $this->assertSame(429, $limited->status);
        $this->assertArrayHasKey('Retry-After', $limited->headers);

        // Hosted clients share a few egress addresses across all their users.
        $this->assertSame(401, $endpoints->handle(OAuthEndpoints::TOKEN, $token)->status);
        $this->assertSame(401, $endpoints->handle(OAuthEndpoints::TOKEN, $token)->status);
    }
}
