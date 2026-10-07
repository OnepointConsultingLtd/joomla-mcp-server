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
 * The client closed the request's stream. On Streamable HTTP that is the
 * cancellation signal: the work stops and nothing more is sent for it.
 */
final class ProgressCancelled extends \RuntimeException
{
}
