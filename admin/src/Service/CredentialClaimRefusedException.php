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
 * A credential request cannot be claimed, for a reason the owner can act on.
 * Its message is written for them and safe to show; any other exception from
 * a claim is a fault and is not.
 */
final class CredentialClaimRefusedException extends \RuntimeException
{
}
