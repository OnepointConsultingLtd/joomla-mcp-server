<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\View\Authorize;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * The OAuth consent page and its error page. Rendered only by
 * OauthController, which fills $oauth; never reached through view=authorize.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string,mixed> */
    public array $oauth = [];

    public function display($tpl = null): void
    {
        if ($this->oauth === []) {
            throw new \Exception(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        // The application's document, not getDocument(): that only exists from Joomla 4.4.
        $document = Factory::getApplication()->getDocument();
        $document->setTitle(Text::_('COM_MCPSERVER_OAUTH_PAGE_TITLE'));
        $document->setMetaData('robots', 'noindex, nofollow');

        parent::display($tpl);
    }
}
