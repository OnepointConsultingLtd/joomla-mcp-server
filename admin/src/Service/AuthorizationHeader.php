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
 * Reads the request's Authorization header.
 *
 * Under CGI/FastCGI, PHP does not see the header itself; the usual
 * `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` restores
 * it, but after an internal rewrite Apache renames that variable to
 * REDIRECT_HTTP_AUTHORIZATION. Both have to be checked.
 */
final class AuthorizationHeader
{
    /**
     * @param callable(string $name, string $default): string $server Reads a server variable.
     */
    public static function read(callable $server): string
    {
        return $server('HTTP_AUTHORIZATION', '') ?: $server('REDIRECT_HTTP_AUTHORIZATION', '');
    }
}
