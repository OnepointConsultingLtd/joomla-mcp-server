<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Input\Input;

/**
 * The one place that reads the request's Authorization header and takes the
 * bearer token out of it. The shared bearer token, credential bearer tokens
 * and OAuth access tokens are all read here.
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
     * @param Input $server The request's server input (`$app->input->server`).
     */
    public static function read(Input $server): string
    {
        return $server->getString('HTTP_AUTHORIZATION', '')
            ?: $server->getString('REDIRECT_HTTP_AUTHORIZATION', '');
    }

    /**
     * The token from the request's `Authorization: Bearer <token>` header, or
     * null when there is none.
     */
    public static function bearerTokenFrom(Input $server): ?string
    {
        return self::bearerToken(self::read($server));
    }

    /**
     * The token from an Authorization header value, or null when the header
     * is not a bearer credential.
     *
     * The scheme must be "Bearer", in any letter case (RFC 7235 §2.1),
     * followed by one space, and the rest of the value is the token, returned
     * as sent: no trimming, so a padded token never matches a stored one.
     */
    public static function bearerToken(string $header): ?string
    {
        $prefixLength = strlen(self::BEARER_PREFIX);
        if (strncasecmp($header, self::BEARER_PREFIX, $prefixLength) !== 0) {
            return null;
        }

        $token = substr($header, $prefixLength);

        return $token === '' ? null : $token;
    }
}
