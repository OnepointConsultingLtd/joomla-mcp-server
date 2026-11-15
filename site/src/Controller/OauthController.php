<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Component\Mcpserver\Administrator\Controller\OAuthHandlerTrait;

/**
 * The OAuth authorisation server's oauth.* tasks; see OAuthHandlerTrait.
 */
class OauthController extends BaseController
{
    use OAuthHandlerTrait;
}
