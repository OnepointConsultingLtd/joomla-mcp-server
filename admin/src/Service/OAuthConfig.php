<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Settings and URLs of the built-in OAuth authorisation server.
 *
 * URL layout. The issuer is the site root, so its metadata lives at
 * /.well-known/oauth-authorization-server (RFC 8414), which the system plugin
 * plg_system_mcpserver serves. The endpoints are clean paths under
 * /mcp-oauth/, also answered by that plugin, rather than component URLs:
 * an issuer may not carry a query string, and some clients build the
 * authorisation URL as `endpoint + "?" + params`, which a
 * `index.php?option=...` endpoint would break. Without URL rewriting the
 * paths go through index.php as PATH_INFO (index.php/mcp-oauth/token).
 */
final class OAuthConfig
{
    public const ENDPOINT_PREFIX = 'mcp-oauth/';

    /** The endpoints the plugin answers under ENDPOINT_PREFIX. */
    public const ENDPOINTS = [OAuthEndpoints::AUTHORIZE, OAuthEndpoints::TOKEN, OAuthEndpoints::REGISTER, OAuthEndpoints::REVOKE];

    private const WELL_KNOWN = [
        '.well-known/oauth-authorization-server' => OAuthEndpoints::AUTHORIZATION_SERVER,
        '.well-known/oauth-protected-resource' => OAuthEndpoints::PROTECTED_RESOURCE,
    ];

    private readonly string $siteRoot;

    /**
     * @param string       $siteRoot             Absolute site URL, as Uri::root() returns it.
     * @param list<string> $allowedRedirectHosts https hosts and "scheme:" entries clients may register redirects to; empty allows any.
     */
    public function __construct(
        string $siteRoot,
        public readonly bool $urlRewriting = true,
        public readonly int $accessTokenTtl = 3600,
        public readonly int $refreshTokenTtl = 2592000,
        public readonly bool $registrationEnabled = true,
        public readonly array $allowedRedirectHosts = [],
    ) {
        $this->siteRoot = rtrim($siteRoot, '/') . '/';
    }

    /**
     * OAuth needs Governed Mode: every grant is bound to a governed credential.
     */
    public static function isEnabled(Registry $params): bool
    {
        return (bool) $params->get('governed_mode', false) && (bool) $params->get('oauth_enabled', false);
    }

    public static function fromParams(Registry $params, string $siteRoot, bool $urlRewriting): self
    {
        $hosts = preg_split('/[\s,]+/', strtolower((string) $params->get('oauth_allowed_redirect_hosts', ''))) ?: [];

        return new self(
            $siteRoot,
            $urlRewriting,
            max(300, (int) $params->get('oauth_access_token_ttl', 3600)),
            max(1, (int) $params->get('oauth_refresh_token_ttl_days', 30)) * 86400,
            (bool) $params->get('oauth_allow_registration', true),
            array_values(array_filter($hosts, static fn (string $host): bool => $host !== '')),
        );
    }

    /**
     * Which OAuth URL, if any, a request path is: what plg_system_mcpserver
     * checks on every site request before claiming it.
     *
     * @param string $path     The request path, as Uri::getInstance()->getPath() gives it.
     * @param string $rootPath The site's path, as Uri::root(true) gives it.
     *
     * @return array{0:string,1:string}|null The endpoint (an OAuthEndpoints
     *         constant) and, for metadata, the path after the
     *         well-known name; null for any other request.
     */
    public static function routeForPath(string $path, string $rootPath): ?array
    {
        $rootPath = rtrim($rootPath, '/');
        if ($rootPath !== '' && str_starts_with($path, $rootPath . '/')) {
            $path = substr($path, strlen($rootPath));
        }

        $path = trim($path, '/');
        // Without URL rewriting requests reach Joomla as index.php/<path>.
        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }

        foreach (self::WELL_KNOWN as $prefix => $endpoint) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return [$endpoint, ltrim(substr($path, strlen($prefix)), '/')];
            }
        }

        if (str_starts_with($path, self::ENDPOINT_PREFIX)) {
            $name = substr($path, strlen(self::ENDPOINT_PREFIX));
            if (in_array($name, self::ENDPOINTS, true)) {
                return [$name, ''];
            }
        }

        return null;
    }

    public function siteRoot(): string
    {
        return $this->siteRoot;
    }

    public function issuer(): string
    {
        return rtrim($this->siteRoot, '/');
    }

    public function endpoint(string $name): string
    {
        return $this->siteRoot . ($this->urlRewriting ? '' : 'index.php/') . self::ENDPOINT_PREFIX . $name;
    }

    public function componentUrl(string $task): string
    {
        return ComponentTaskUrl::url($this->siteRoot, $task);
    }

    /** The MCP endpoint, used as the resource when a client names none. */
    public function defaultResource(): string
    {
        return ComponentTaskUrl::mcpEndpoint($this->siteRoot);
    }

    /**
     * The resource a `/.well-known/oauth-protected-resource/<path>` request
     * asks about (RFC 9728 §3.1). Only clients that ignore the
     * resource_metadata link in WWW-Authenticate and build that URL from the
     * MCP endpoint themselves get here.
     *
     * The well-known URL keeps only the endpoint's path, so the query string
     * that makes it the MCP endpoint (`?option=com_mcpserver&task=rpc.handle`)
     * is lost and <path> is just the site's front controller. That path is
     * mapped back to the MCP endpoint at the same URL, so the token is bound
     * to the same resource the resource_metadata link would have named. Any
     * other path is named as asked; ownsResource() still decides whether it
     * is accepted.
     *
     * @param string $origin The request's scheme, host and port (`https://example.com`).
     * @param string $path   The path after the well-known name, as routeForPath() returns it.
     */
    public function resourceForWellKnownPath(string $origin, string $path): string
    {
        $path = trim($path, '/');
        $rootPath = trim((string) parse_url($this->siteRoot, PHP_URL_PATH), '/');
        $frontController = ltrim($rootPath . '/index.php', '/');
        $url = rtrim($origin, '/') . '/';
        $mcpEndpointQuery = '?' . ComponentTaskUrl::query(ComponentTaskUrl::MCP_ENDPOINT);

        if ($path === $frontController) {
            return $url . $path . $mcpEndpointQuery;
        }

        if ($path === $rootPath) {
            return $url . ($path === '' ? '' : $path . '/') . $mcpEndpointQuery;
        }

        return $url . $path;
    }

    /**
     * Where a 401 points the client for Protected Resource Metadata (RFC 9728
     * §5.1). A component URL, so it works with or without the plugin and URL
     * rewriting; $resource is echoed back as the metadata's resource so it
     * matches the URL the client actually connected to.
     */
    public function protectedResourceMetadataUrl(string $resource): string
    {
        return $this->componentUrl('oauth.resource') . '&resource=' . rawurlencode($resource);
    }

    /**
     * Whether $uri identifies the site's MCP endpoint, in any of its URL
     * forms: same scheme, host and port as the site root, a path at or below
     * the root path but not in the administrator, and no fragment (RFC 8707
     * §2). OAuth grants are for the site application only.
     */
    public function ownsResource(string $uri): bool
    {
        if ($uri === '' || str_contains($uri, '#') || strlen($uri) > 2000) {
            return false;
        }

        $root = parse_url($this->siteRoot);
        $parts = parse_url($uri);
        if ($root === false || $parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $port = static fn (array $p): int => (int) ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));

        $rootPath = rtrim($root['path'] ?? '/', '/') . '/';
        $path = rtrim($parts['path'] ?? '/', '/') . '/';

        return strtolower($parts['scheme']) === strtolower((string) $root['scheme'])
            && strtolower($parts['host']) === strtolower((string) $root['host'])
            && $port($parts) === $port($root)
            && str_starts_with($path, $rootPath)
            && !str_starts_with(strtolower($path), strtolower($rootPath) . 'administrator/');
    }
}
