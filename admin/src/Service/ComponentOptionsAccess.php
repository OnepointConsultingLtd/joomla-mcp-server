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
 * Who may open the component's Options (com_config).
 *
 * The same test com_config applies before it shows a component's Options:
 * Configure ACL & Options (`core.admin`) or Configure Options
 * (`core.options`) on the component. Both inherit from the site-wide
 * actions, so Super Users always pass.
 */
final class ComponentOptionsAccess
{
    private const COMPONENT = 'com_mcpserver';

    /**
     * @param object|null $user A Joomla\CMS\User\User; only authorise() is used.
     */
    public static function allows(?object $user): bool
    {
        return $user !== null
            && ($user->authorise('core.admin', self::COMPONENT) || $user->authorise('core.options', self::COMPONENT));
    }
}
