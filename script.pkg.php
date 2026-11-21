<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;

class pkg_mcpserverInstallerScript
{
    /**
     * Enables the System - MCP Server plugin when the package is first
     * installed, including over a component-only install (1.11.x and earlier),
     * which is a package "install" too. Updates leave it as the site has it,
     * so a site that disabled it keeps it disabled. The plugin does nothing
     * until OAuth Sign-in is switched on.
     */
    public function postflight(string $type, InstallerAdapter $parent): void
    {
        if ($type !== 'install' && $type !== 'discover_install') {
            return;
        }

        try {
            $db = Factory::getDbo();
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('mcpserver'))
            )->execute();
        } catch (\Throwable) {
            // Not fatal: the plugin can be enabled under System → Plugins, and
            // the OAuth Sign-in option explains that it is needed.
        }
    }
}
