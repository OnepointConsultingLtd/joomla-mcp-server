<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\View\Mcpcomponent;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\McpbService;

/**
 * MCP Component Landing View
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var array
     */
    public $mcpConfig;

    /** @var bool */
    public bool $governedMode = false;

    /**
     * Display the view
     *
     * @param   string  $tpl  Template
     * @return  void
     */
    public function display($tpl = null)
    {
        ToolbarHelper::title('MCP Server', 'mcp');
        ToolbarHelper::preferences('com_mcpserver');
        
        $params = ComponentHelper::getParams('com_mcpserver');
        $this->governedMode = (bool) $params->get('governed_mode', 0);
        $this->mcpConfig = $this->generateMcpConfig($params);
        
        parent::display($tpl);
    }

    /**
     * Generate MCP configuration for clients
     *
     * @return array
     */
    private function generateMcpConfig(\Joomla\Registry\Registry $params): array
    {
        $rpcUrl = $this->getMcpbService()->endpointUrl();
        $token = (string) $params->get('mcp_bearer_token', '');
        
        $args = [
            '-y',
            'mcp-remote',
            $rpcUrl,
        ];

        $server = [
            'command' => 'npx',
            'args' => $args,
        ];

        // Pass the bearer token through an env var so it stays out of the args list.
        if ($this->governedMode || ($params->get('require_auth', 0) && $token !== '')) {
            $server['args'][] = '--header';
            $server['args'][] = 'Authorization:${AUTH_HEADER}';
            $server['env'] = [
                'AUTH_HEADER' => $this->governedMode
                    ? 'Bearer <YOUR_GOVERNED_CREDENTIAL>'
                    : 'Bearer <YOUR_TOKEN>',
            ];
        }

        $config = [
            'mcpServers' => [
                'joomla' => $server,
            ],
        ];

        $maskedToken = '';
        if ($token !== '') {
            $maskedToken = strlen($token) > 4
                ? str_repeat('*', strlen($token) - 4) . substr($token, -4)
                : str_repeat('*', strlen($token));
        }

        return [
            'json' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'url' => $rpcUrl,
            'token' => $this->governedMode ? '' : $token,
            'maskedToken' => $this->governedMode ? '' : $maskedToken,
        ];
    }

    /**
     * Resolve McpbService from the DI container, with a direct fallback
     * mirroring the pattern used by the dashboard view.
     */
    private function getMcpbService(): McpbService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has(McpbService::class)) {
            return $container->get(McpbService::class);
        }

        return new McpbService(ComponentHelper::getParams('com_mcpserver'));
    }
}
