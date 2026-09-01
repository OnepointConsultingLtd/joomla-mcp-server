<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/** @var \Joomla\Component\Mcpserver\Administrator\View\Mcpcomponent\HtmlView $this */

HTMLHelper::_('bootstrap.tooltip');
HTMLHelper::_('behavior.core');
?>
<div class="container py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="display-5">MCP Server</h1>
            <p class="lead"><?php echo Text::_($this->governedMode ? 'COM_MCPSERVER_COMPONENT_INTRO_GOVERNED' : 'COM_MCPSERVER_COMPONENT_INTRO'); ?></p>
        </div>
        <div class="col-md-4 text-end">
            <a class="btn btn-outline-secondary me-1" href="index.php?option=com_mcpserver&amp;view=dashboard">
                <span class="icon-chart" aria-hidden="true"></span>
                <?php echo Text::_('COM_MCPSERVER_SUBMENU_DASHBOARD'); ?>
            </a>
            <a class="btn btn-primary" href="index.php?option=com_config&amp;view=component&amp;component=com_mcpserver">
                <span class="icon-options" aria-hidden="true"></span>
                <?php echo Text::_('JOPTIONS'); ?>
            </a>
        </div>
    </div>

    <?php if ($this->governedMode): ?>
        <div class="alert alert-info mb-4" role="status">
            <h2 class="h5 alert-heading"><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_DESC'); ?></p>
            <ol class="mb-3">
                <li><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_STEP_1'); ?></li>
                <li><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_STEP_2'); ?></li>
                <li><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_STEP_3'); ?></li>
                <li><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_STEP_4'); ?></li>
            </ol>
            <a class="btn btn-primary" href="index.php?option=com_mcpserver&amp;task=credentials.display">
                <span class="icon-key" aria-hidden="true"></span>
                <?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_CREDENTIALS_BUTTON'); ?>
            </a>
        </div>
    <?php endif; ?>
    
    <div class="card mb-4 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">MCP Client Configuration</h5>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-primary" href="index.php?option=com_mcpserver&amp;task=mcpb.download&amp;<?php echo Session::getFormToken(); ?>=1">
                    <span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('COM_MCPSERVER_MCPB_DOWNLOAD'); ?>
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard(this)">
                    <span class="icon-copy" aria-hidden="true"></span> Copy JSON
                </button>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2"><?php echo Text::_($this->governedMode ? 'COM_MCPSERVER_MANUAL_CONFIG_GOVERNED_DESC' : 'COM_MCPSERVER_MANUAL_CONFIG_LEGACY_DESC'); ?></p>
            <div class="position-relative">
                <pre id="mcpConfigJson" class="m-0 p-3 border rounded bg-body-secondary text-body"><code class="language-json"><?php echo htmlspecialchars($this->mcpConfig['json']); ?></code></pre>
            </div>
        </div>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Connection Details</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <tbody>
                    <tr>
                        <th class="ps-3" style="width: 200px;">RPC Endpoint</th>
                        <td><code><?php echo htmlspecialchars($this->mcpConfig['url']); ?></code></td>
                    </tr>
                    <tr>
                        <th class="ps-3">Authentication</th>
                        <td>
                            <?php if ($this->governedMode): ?>
                                <span class="badge bg-success"><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_AUTH'); ?></span>
                                <span class="ms-2"><?php echo Text::_('COM_MCPSERVER_MANUAL_GOVERNED_AUTH_DESC'); ?></span>
                            <?php elseif ($this->mcpConfig['token']): ?>
                                <span class="badge bg-success">Bearer Token Enabled</span>
                                <span class="ms-2">
                                    <code id="tokenDisplay"><?php echo htmlspecialchars($this->mcpConfig['maskedToken']); ?></code>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="tokenRevealBtn" onclick="toggleTokenReveal()">
                                        <span class="icon-eye" aria-hidden="true"></span> Reveal
                                    </button>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">No Authentication Set</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
var _tokenRevealed = false;
var _maskedToken = <?php echo json_encode($this->mcpConfig['maskedToken']); ?>;
var _realToken = <?php echo json_encode($this->mcpConfig['token']); ?>;

function toggleTokenReveal() {
    var display = document.getElementById('tokenDisplay');
    var btn = document.getElementById('tokenRevealBtn');
    if (!display || !btn) return;
    _tokenRevealed = !_tokenRevealed;
    if (_tokenRevealed) {
        display.textContent = _realToken;
        btn.innerHTML = '<span class="icon-eye-close" aria-hidden="true"></span> Hide';
    } else {
        display.textContent = _maskedToken;
        btn.innerHTML = '<span class="icon-eye" aria-hidden="true"></span> Reveal';
    }
}

function copyToClipboard(btn) {
    const codeElement = document.querySelector('#mcpConfigJson code');
    if (!codeElement) return;

    const text = codeElement.innerText;
    
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="icon-check" aria-hidden="true"></span> Copied!';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
        // Fallback for older browsers
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            btn.innerHTML = '<span class="icon-check" aria-hidden="true"></span> Copied!';
            setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
        } catch (e) {}
        document.body.removeChild(textArea);
    });
}
</script>

<style>
#mcpConfigJson {
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.9rem;
    line-height: 1.4;
    max-height: 500px;
    overflow-y: auto;
}
#mcpConfigJson code {
    color: inherit;
}
.card-header .btn-sm {
    font-size: 0.75rem;
}
</style>
