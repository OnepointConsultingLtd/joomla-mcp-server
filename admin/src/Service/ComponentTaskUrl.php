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
 * URLs that run one of the component's front-end tasks through the site's
 * front controller: `<site root>/index.php?option=com_mcpserver&task=<task>`.
 *
 * The most important is the MCP endpoint,
 * `<site root>/index.php?option=com_mcpserver&task=rpc.handle`. MCP clients
 * connect to it, OAuth access tokens are bound to it (as their resource), the
 * Claude Desktop extension and the client configuration card hand it out,
 * and the HTTP+SSE stream tells clients to POST to it. Every one of them
 * builds the URL here, so they can't drift apart.
 */
final class ComponentTaskUrl
{
    /** The task that serves MCP requests. */
    public const MCP_ENDPOINT = 'rpc.handle';

    /** The query string that runs $task, such as `oauth.resource`. */
    public static function query(string $task): string
    {
        return 'option=com_mcpserver&task=' . $task;
    }

    /**
     * @param string $siteRoot The site's absolute URL, with or without a trailing slash.
     */
    public static function url(string $siteRoot, string $task): string
    {
        return rtrim($siteRoot, '/') . '/index.php?' . self::query($task);
    }

    /**
     * The MCP endpoint on the site at $siteRoot.
     *
     * @param string $siteRoot The site's absolute URL, with or without a trailing slash.
     */
    public static function mcpEndpoint(string $siteRoot): string
    {
        return self::url($siteRoot, self::MCP_ENDPOINT);
    }
}
