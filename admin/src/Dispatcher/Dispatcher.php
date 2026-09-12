<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;

class Dispatcher extends ComponentDispatcher
{
    public function dispatch(): void
    {
        // This override replaces ComponentDispatcher::dispatch() wholesale, so
        // the base class's access gate has to be reinstated explicitly. Without
        // it any account holding only core.login.admin could open
        // view=dashboard and read the request log. The site dispatcher is a
        // separate class and is deliberately not gated: it serves the public
        // JSON-RPC endpoint, which authenticates per request instead.
        $this->checkAccess();

        $task = $this->input->get('task', '');

        if (str_contains($task, '.')) {
            [$name, $action] = explode('.', $task, 2);
        } else {
            $name = $task ?: 'display';
            $action = 'display';
        }
        
        $name = ucfirst(strtolower($name));
        
        if ($name === '') {
            $name = 'Display';
        }
        
        $controller = $this->getController($name, 'Administrator', ['task' => $action]);
        $controller->execute($action);
        $controller->redirect();
    }

    /**
     * Gate the administrator side on core.manage OR mcpserver.credential.self.
     *
     * The base implementation checks core.manage alone, which would lock out
     * exactly the people the self-service credential page exists for:
     * mcpserver.credential.self is designed to be sufficient on its own, so a
     * user granted only that must still be able to reach the component. The
     * individual views and controllers apply their own, narrower checks.
     */
    protected function checkAccess()
    {
        if (!$this->app->isClient('administrator')) {
            return;
        }

        $user = $this->app->getIdentity();

        if (
            $user !== null
            && (
                $user->authorise('core.manage', 'com_mcpserver')
                || $user->authorise('mcpserver.credential.self', 'com_mcpserver')
            )
        ) {
            return;
        }

        throw new NotAllowed($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
    }
}
