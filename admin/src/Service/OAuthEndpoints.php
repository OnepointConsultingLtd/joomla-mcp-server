<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Psr\Log\LoggerInterface;

/**
 * The machine-facing OAuth endpoints: token, registration, revocation and the
 * two metadata documents. The authorisation endpoint is not here, because it
 * is a page a person signs in to (see OAuthHandlerTrait::authorize()).
 *
 * Served both by plg_system_mcpserver (at /.well-known/... and /mcp-oauth/...)
 * and by the component's oauth.* tasks, so both go through this one class.
 */
final class OAuthEndpoints
{
    /** The authorisation page: served by the component, not by this class. */
    public const AUTHORIZE = 'authorize';
    public const TOKEN = 'token';
    public const REGISTER = 'register';
    public const REVOKE = 'revoke';
    public const AUTHORIZATION_SERVER = 'authorization-server';
    public const PROTECTED_RESOURCE = 'protected-resource';

    /**
     * Throttled per IP like the RPC endpoint: registration only, the one
     * endpoint that writes for an unauthenticated caller. The token endpoint
     * is not, because ChatGPT and Claude call it for every user from a few
     * shared addresses, so a per-IP limit would log users out on a busy site,
     * while its 256-bit codes and tokens make throttling pointless anyway.
     */
    private const RATE_LIMITED = [self::REGISTER];

    private const CORS = [
        // These endpoints use no cookies, so any origin may call them; browser
        // based MCP clients (such as the MCP Inspector) need that to sign in.
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version',
        'Access-Control-Max-Age' => '3600',
    ];

    private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    public function __construct(
        private readonly OAuthServer $server,
        private readonly ?RateLimiter $rateLimiter,
        private readonly string $resourceName,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(string $endpoint, OAuthHttpRequest $request): OAuthHttpResponse
    {
        try {
            return $this->dispatch($endpoint, $request)->withHeaders(self::CORS);
        } catch (OAuthError $e) {
            $headers = self::NO_STORE + self::CORS;
            if ($e->httpStatus === 401 && $request->authorization !== '') {
                $headers['WWW-Authenticate'] = 'Basic realm="mcp-oauth"';
            }

            return OAuthHttpResponse::json($e->httpStatus, $e->toArray(), $headers);
        } catch (\Throwable $e) {
            $this->logger?->error('OAuth endpoint failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return OAuthHttpResponse::json(500, ['error' => 'server_error', 'error_description' => 'The request could not be completed'], self::NO_STORE + self::CORS);
        }
    }

    private function dispatch(string $endpoint, OAuthHttpRequest $request): OAuthHttpResponse
    {
        $method = strtoupper($request->method);
        if ($method === 'OPTIONS') {
            return new OAuthHttpResponse(204);
        }

        $isMetadata = in_array($endpoint, [self::AUTHORIZATION_SERVER, self::PROTECTED_RESOURCE], true);
        $allowed = $isMetadata ? ['GET', 'HEAD'] : ['POST'];
        if (!in_array($method, $allowed, true)) {
            return new OAuthHttpResponse(405, ['Allow' => implode(', ', $allowed) . ', OPTIONS']);
        }

        if (in_array($endpoint, self::RATE_LIMITED, true) && $this->rateLimiter !== null) {
            $limit = $this->rateLimiter->checkLimit('oauth:' . $request->clientIp);
            if ($limit !== null) {
                return OAuthHttpResponse::json(
                    429,
                    ['error' => 'temporarily_unavailable', 'error_description' => 'Rate limit exceeded'],
                    ['Retry-After' => (string) $limit['retry_after']] + self::NO_STORE
                );
            }
        }

        $config = $this->server->config();

        return match ($endpoint) {
            self::AUTHORIZATION_SERVER => OAuthHttpResponse::json(200, OAuthMetadata::authorizationServer($config), ['Cache-Control' => 'max-age=300']),
            self::PROTECTED_RESOURCE => OAuthHttpResponse::json(
                200,
                OAuthMetadata::protectedResource($config, $request->queryParam('resource'), $this->resourceName),
                ['Cache-Control' => 'max-age=300']
            ),
            self::TOKEN => OAuthHttpResponse::json(200, $this->server->token($request->post, $request->authorization), self::NO_STORE),
            self::REVOKE => $this->revoke($request),
            self::REGISTER => $this->register($request->body),
            default => OAuthHttpResponse::json(404, ['error' => 'not_found']),
        };
    }

    private function revoke(OAuthHttpRequest $request): OAuthHttpResponse
    {
        $this->server->revoke($request->post, $request->authorization);

        return new OAuthHttpResponse(200, self::NO_STORE);
    }

    private function register(string $body): OAuthHttpResponse
    {
        if (!$this->server->config()->registrationEnabled) {
            throw new OAuthError('access_denied', 'Client registration is disabled on this site', 403);
        }

        $metadata = json_decode($body, true);
        if (!is_array($metadata) || array_is_list($metadata) && $metadata !== []) {
            throw new OAuthError('invalid_client_metadata', 'The request body must be a JSON object');
        }

        return OAuthHttpResponse::json(201, $this->server->register($metadata), self::NO_STORE);
    }
}
