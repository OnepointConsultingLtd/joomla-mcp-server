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
 * Where a request's progress notifications go while the request is still running.
 */
interface ProgressSink
{
    /**
     * Deliver one JSON-RPC notification now.
     *
     * @param  array<string, mixed>  $notification
     */
    public function send(array $notification): void;

    /**
     * Whether the client has gone away since the last send.
     */
    public function isAborted(): bool;
}
