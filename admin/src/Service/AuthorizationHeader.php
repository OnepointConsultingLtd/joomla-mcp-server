<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * The one place that reads the request's Authorization header and takes the
 * bearer token out of it. Legacy, Governed Mode and OAuth authentication all
 * go through here.
 *
 * Under CGI/FastCGI, PHP does not see the header itself; the usual
 * `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` restores
 * it, but after an internal rewrite Apache renames that variable to
 * REDIRECT_HTTP_AUTHORIZATION. Both have to be checked.
 */
final class AuthorizationHeader
{
    private const BEARER_PREFIX = 'Bearer ';

    /**
     * @param object $server The request's server input (`$app->input->server`,
     *                       a Joomla\Input\Input); only getString() is used.
     */
    public static function read(object $server): string
    {
        return $server->getString('HTTP_AUTHORIZATION', '')
            ?: $server->getString('REDIRECT_HTTP_AUTHORIZATION', '');
    }

    /**
     * The token from the request's `Authorization: Bearer <token>` header, or
     * null when there is none.
     */
    public static function bearerTokenFrom(object $server): ?string
    {
        return self::bearerToken(self::read($server));
    }

    /**
     * The token from an Authorization header value, or null when the header
     * is not a bearer credential.
     *
     * The scheme must be exactly "Bearer" followed by one space, and the rest
     * of the value is the token, returned as sent: no trimming, so a padded
     * token never matches a stored one.
     */
    public static function bearerToken(string $header): ?string
    {
        if (!str_starts_with($header, self::BEARER_PREFIX)) {
            return null;
        }

        $token = substr($header, strlen(self::BEARER_PREFIX));

        return $token === '' ? null : $token;
    }
}
