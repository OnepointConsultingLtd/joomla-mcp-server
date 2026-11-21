<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

/**
 * Builds the OAuth services from Joomla's globals. Shared by the component's
 * oauth.* tasks and by plg_system_mcpserver, which runs before any component
 * has been dispatched and so cannot rely on the DI container.
 */
final class OAuthServiceFactory
{
    /** Parameters each endpoint reads. Only these are taken from the request, unfiltered. */
    public const AUTHORIZE_PARAMS = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    private const POST_PARAMS = [
        'grant_type', 'code', 'redirect_uri', 'code_verifier', 'client_id', 'client_secret',
        'refresh_token', 'scope', 'resource', 'token', 'token_type_hint',
    ];

    public static function params(): Registry
    {
        return ComponentHelper::getParams('com_mcpserver');
    }

    public static function config(?Registry $params = null): OAuthConfig
    {
        return OAuthConfig::fromParams(
            $params ?? self::params(),
            Uri::root(),
            (bool) Factory::getApplication()->get('sef_rewrite', false)
        );
    }

    /**
     * @param OAuthCredentialClaimsInterface|null $claims Lets the consent page claim
     *        approved credential requests; only the authorisation endpoint needs it.
     */
    public static function server(?Registry $params = null, ?OAuthCredentialClaimsInterface $claims = null): OAuthServer
    {
        return new OAuthServer(
            self::store(),
            self::config($params),
            static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new HttpClientMetadataFetcher(),
            $claims
        );
    }

    /**
     * @param string $requestUrl The URL the access token is being presented to.
     */
    public static function accessTokenAuthenticator(string $requestUrl, ?Registry $params = null): OAuthAccessTokenAuthenticator
    {
        return new OAuthAccessTokenAuthenticator(self::store(), self::config($params), $requestUrl);
    }

    private static function store(): JoomlaOAuthStore
    {
        return new JoomlaOAuthStore(Factory::getDbo());
    }

    public static function endpoints(?Registry $params = null): OAuthEndpoints
    {
        $params ??= self::params();

        return new OAuthEndpoints(
            self::server($params),
            RateLimiter::fromParams($params),
            (string) $params->get('server_name', 'joomla-mcp-server')
        );
    }

    /**
     * The current request as OAuthEndpoints::handle() takes it.
     */
    public static function currentRequest(Input $input, Registry $params): OAuthHttpRequest
    {
        return new OAuthHttpRequest(
            strtoupper($input->getMethod()),
            self::rawParams($input->get, ['resource']),
            self::rawParams($input->post, self::POST_PARAMS),
            (string) file_get_contents('php://input'),
            AuthorizationHeader::read($input->server),
            (new AuthService($params))->getClientIp() ?: 'unknown',
        );
    }

    /**
     * Reads the named parameters without Joomla's default input filter,
     * which would strip or decode characters in URIs and PKCE values.
     *
     * @param list<string> $names
     *
     * @return array<string,mixed>
     */
    public static function rawParams(Input $source, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $value = $source->get($name, null, 'raw');
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
