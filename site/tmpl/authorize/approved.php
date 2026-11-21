<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Confirms an approval in the site's own template, then hands the browser on
 * to the client's redirect URI. The client's page is its own (for a CLI, a
 * bare page on localhost), so this is the last page the site can style.
 *
 * The hand-over is a meta refresh, not script, so it works with JavaScript
 * off; the link is the fallback, and the only way on where a browser will not
 * open a private-use scheme URI without a click. Two seconds is well inside
 * the authorisation code's lifetime.
 */

/** @var \Joomla\Component\Mcpserver\Administrator\View\Authorize\HtmlView $this */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$redirectUrl = $e((string) $this->oauth['redirectUrl']);
?>
<meta http-equiv="refresh" content="2;url=<?php echo $redirectUrl; ?>">
<div class="com-mcpserver-oauth">
    <h1><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_APPROVED_HEADING', $e((string) $this->oauth['clientName']), $e((string) $this->oauth['siteName'])); ?></h1>

    <div class="alert alert-success">
        <p><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_APPROVED_ACTS_AS', $e((string) $this->oauth['userName'])); ?></p>
        <p class="mb-0"><?php echo Text::_('COM_MCPSERVER_OAUTH_APPROVED_HANDOVER'); ?></p>
    </div>

    <p class="small text-muted"><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_APPROVED_UNVERIFIED_NAME', $e((string) $this->oauth['clientName'])); ?></p>

    <p>
        <a class="btn btn-primary" href="<?php echo $redirectUrl; ?>">
            <?php echo Text::_('COM_MCPSERVER_OAUTH_APPROVED_CONTINUE'); ?>
        </a>
    </p>
</div>
