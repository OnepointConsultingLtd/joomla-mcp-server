<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class AuthService
{
    private Registry $config;
    private ?GovernedAuthService $governedAuthService;

    private bool $resolved = false;

    /** @var AuthenticatedPrincipal|array{error:string,code:int}|null */
    private AuthenticatedPrincipal|array|null $resolvedResult = null;

    public function __construct(Registry $config, ?GovernedAuthService $governedAuthService = null)
    {
        $this->config = $config;
        $this->governedAuthService = $governedAuthService;
    }

    public function authenticate(): ?array
    {
        $result = $this->resolve();

        return $result instanceof AuthenticatedPrincipal ? null : $result;
    }

    public function authenticatePrincipal(): ?AuthenticatedPrincipal
    {
        $result = $this->resolve();

        return $result instanceof AuthenticatedPrincipal ? $result : null;
    }

    /**
     * Memoized: callers (RpcHandlerTrait::handle()/sse()) call
     * authenticatePrincipal() then, on a null principal, authenticate() —
     * both must observe the same single authentication attempt rather than
     * re-running governed-credential verification (and its touchLastUsed
     * side effect) or legacy token comparison a second time per request.
     *
     * @return AuthenticatedPrincipal|array{error:string,code:int}|null
     */
    private function resolve(): AuthenticatedPrincipal|array|null
    {
        if ($this->resolved) {
            return $this->resolvedResult;
        }

        $this->resolved = true;

        return $this->resolvedResult = $this->doResolve();
    }

    /**
     * @return AuthenticatedPrincipal|array{error:string,code:int}|null
     */
    private function doResolve(): AuthenticatedPrincipal|array|null
    {
        $ipAllowList = array_filter(array_map('trim', explode(',', $this->config->get('ip_allow_list', ''))));

        if (!empty($ipAllowList)) {
            $remoteIp = $this->getClientIp();
            if (!in_array($remoteIp, $ipAllowList, true)) {
                return ['error' => 'IP not allowed', 'code' => JsonRpc::FORBIDDEN];
            }
        }

        $governedMode = (bool) $this->config->get('governed_mode', false);

        if ($governedMode) {
            if ($this->governedAuthService === null) {
                return ['error' => 'Governed authentication is not configured', 'code' => JsonRpc::FORBIDDEN];
            }

            return $this->governedAuthService->authenticate();
        }

        return $this->authenticateLegacy();
    }

    private function authenticateLegacy(): ?array
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $mcpToken = $this->config->get('mcp_bearer_token', '');

        // Fail closed. Joomla only persists config.xml field defaults (require_auth
        // defaults to 1 there) once an admin saves the options; until then
        // ComponentHelper::getParams() returns an empty registry. A fallback of
        // false would leave the public endpoint unauthenticated on a fresh install,
        // so default to requiring auth when the param has never been stored.
        $requireAuth = (bool) $this->config->get('require_auth', true);

        if ($requireAuth) {
            if (empty($mcpToken)) {
                return ['error' => 'Authentication required but no server token configured', 'code' => JsonRpc::FORBIDDEN];
            }

            $authHeader = $input->server->getString('HTTP_AUTHORIZATION', '');
            if (empty($authHeader)) {
                $authHeader = $input->server->getString('REDIRECT_HTTP_AUTHORIZATION', '');
            }

            $providedToken = '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $providedToken = substr($authHeader, 7);
            }

            if (empty($providedToken)) {
                return ['error' => 'Missing bearer token in Authorization header', 'code' => JsonRpc::UNAUTHORIZED];
            }

            if (!hash_equals($mcpToken, $providedToken)) {
                return ['error' => 'Invalid token', 'code' => JsonRpc::UNAUTHORIZED];
            }
        }

        return null;
    }

    public function getClientIp(): string
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $remoteAddr = $input->server->getString('REMOTE_ADDR', '');

        // Only trust X-Forwarded-For when the direct connection is from a configured trusted proxy
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) $this->config->get('trusted_proxies', ''))));

        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            $forwarded = $input->server->getString('HTTP_X_FORWARDED_FOR', '');
            if (!empty($forwarded)) {
                $ips = array_map('trim', explode(',', $forwarded));
                return $ips[0];
            }
        }

        return $remoteAddr;
    }
}

