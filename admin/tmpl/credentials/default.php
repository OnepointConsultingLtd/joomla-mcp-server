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

/** @var \Joomla\Component\Mcpserver\Administrator\View\Credentials\HtmlView $this */

HTMLHelper::_('bootstrap.tooltip');
?>
<div class="container-fluid py-3">

    <?php if (!$this->governedConfigured): ?>
        <div class="alert alert-warning" role="alert">
            <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->isCoreAdmin): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_TITLE'); ?></h5>
            </div>
            <div class="card-body">
                <p class="text-muted small"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_DESC'); ?></p>
                <dl class="row">
                    <dt class="col-sm-3"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_STATUS_LABEL'); ?></dt>
                    <dd class="col-sm-9">
                        <?php if ($this->governanceStatus['configured']): ?>
                            <span class="badge bg-success"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_STATUS_ACTIVE'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_STATUS_INACTIVE'); ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($this->governanceStatus['recovery_key_fingerprint'] !== null): ?>
                        <dt class="col-sm-3"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_FINGERPRINT_LABEL'); ?></dt>
                        <dd class="col-sm-9">
                            <code><?php echo htmlspecialchars($this->governanceStatus['recovery_key_fingerprint'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <p class="form-text mb-0"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_FINGERPRINT_DESC'); ?></p>
                        </dd>
                    <?php endif; ?>
                </dl>
                <form action="index.php?option=com_mcpserver&amp;task=credentials.setup" method="post">
                    <div class="mb-3">
                        <label class="form-label" for="metrics_retention_days"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_RETENTION_LABEL'); ?></label>
                        <input type="number" class="form-control" id="metrics_retention_days" name="metrics_retention_days" value="360" min="1" max="3650" style="max-width: 160px;">
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_BUTTON'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($this->governedConfigured): ?>

        <?php if ($this->justIssued !== null): ?>
            <div class="alert alert-success" role="alert">
                <h5 class="alert-heading"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_TITLE'); ?></h5>
                <p><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_WARNING'); ?></p>
                <dl class="row mb-0">
                    <dt class="col-sm-2"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_ID'); ?></dt>
                    <dd class="col-sm-10"><code><?php echo htmlspecialchars((string) $this->justIssued['id'], ENT_QUOTES, 'UTF-8'); ?></code></dd>
                    <dt class="col-sm-2"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_TOKEN'); ?></dt>
                    <dd class="col-sm-10">
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control font-monospace bg-body text-body"
                                id="issuedBearerToken"
                                value="<?php echo htmlspecialchars((string) $this->justIssued['bearer_token'], ENT_QUOTES, 'UTF-8'); ?>"
                                readonly
                                aria-describedby="issuedBearerTokenHelp"
                            >
                            <button class="btn btn-dark" type="button" id="copyIssuedBearerToken">
                                <span class="icon-copy" aria-hidden="true"></span>
                                <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COPY_TOKEN'); ?>
                            </button>
                        </div>
                        <div class="form-text text-body" id="issuedBearerTokenHelp"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COPY_TOKEN_DESC'); ?></div>
                    </dd>
                </dl>
            </div>
            <script>
                document.getElementById('copyIssuedBearerToken')?.addEventListener('click', async function () {
                    var input = document.getElementById('issuedBearerToken');
                    if (!input) return;

                    input.select();
                    try {
                        await navigator.clipboard.writeText(input.value);
                    } catch (error) {
                        document.execCommand('copy');
                    }

                    this.classList.replace('btn-dark', 'btn-success');
                    this.textContent = <?php echo json_encode(Text::_('COM_MCPSERVER_CREDENTIALS_TOKEN_COPIED')); ?>;
                });
            </script>
        <?php endif; ?>

        <?php if ($this->isCoreAdmin): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CREATE_TITLE'); ?></h5>
            </div>
            <div class="card-body">
                <form action="index.php?option=com_mcpserver&amp;task=credentials.create" method="post">
                    <div class="mb-3">
                        <label class="form-label" for="api_token"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_API_TOKEN_LABEL'); ?></label>
                        <input type="password" class="form-control" id="api_token" name="api_token" required autocomplete="off">
                        <div class="form-text"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_API_TOKEN_DESC'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="expires_days"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_DAYS_LABEL'); ?></label>
                        <input type="number" class="form-control" id="expires_days" name="expires_days" value="30" min="1" max="3650" style="max-width: 160px;">
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CREATE_BUTTON'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" role="alert">
            <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUANCE_REQUIRES_ADMIN'); ?>
        </div>
        <?php endif; ?>

        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_LIST_TITLE'); ?></h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($this->credentials)): ?>
                    <p class="text-muted p-3 mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_NO_DATA'); ?></p>
                <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_NAME'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_SELECTOR'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_CREATED'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_EXPIRES'); ?></th>
                                <th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_STATUS'); ?></th>
                                <th class="pe-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_ACTIONS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->credentials as $credential): ?>
                                <tr>
                                    <td class="ps-3"><?php echo htmlspecialchars($credential['owner_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><code><?php echo htmlspecialchars($credential['selector'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars(HTMLHelper::_('date', $credential['created_at'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(HTMLHelper::_('date', $credential['expires_at'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($credential['revoked']): ?>
                                            <span class="badge bg-secondary"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_REVOKED'); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_ACTIVE'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3">
                                        <?php if (!$credential['revoked']): ?>
                                            <form action="index.php?option=com_mcpserver&amp;task=credentials.revoke" method="post" onsubmit="return confirm(<?php echo json_encode(Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_CONFIRM')); ?>);">
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $credential['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_BUTTON'); ?></button>
                                                <?php echo HTMLHelper::_('form.token'); ?>
                                            </form>
                                        <?php elseif ($this->isCoreAdmin): ?>
                                            <form action="index.php?option=com_mcpserver&amp;task=credentials.delete" method="post" onsubmit="return confirm(<?php echo json_encode(Text::_('COM_MCPSERVER_CREDENTIALS_DELETE_CONFIRM')); ?>);">
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $credential['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_DELETE_BUTTON'); ?></button>
                                                <?php echo HTMLHelper::_('form.token'); ?>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>
