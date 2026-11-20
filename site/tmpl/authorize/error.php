<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Joomla\Component\Mcpserver\Administrator\View\Authorize\HtmlView $this */
?>
<div class="com-mcpserver-oauth">
    <h1><?php echo Text::_('COM_MCPSERVER_OAUTH_ERROR_HEADING'); ?></h1>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars((string) $this->oauth['message'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
</div>
