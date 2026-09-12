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
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Mcpserver\Administrator\View\Credentials\HtmlView $this */

HTMLHelper::_('bootstrap.tooltip');
?>
<div class="container-fluid py-3">

    <?php if (!$this->governedConfigured && $this->loadError !== null): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo Text::sprintf('COM_MCPSERVER_CREDENTIALS_LOAD_FAILED', htmlspecialchars($this->loadError, ENT_QUOTES, 'UTF-8')); ?>
        </div>
    <?php elseif (!$this->governedConfigured): ?>
        <div class="alert alert-warning" role="alert">
            <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->justIssued && !$this->governedConfigured): ?>
        <div class="alert alert-info" role="alert">
            <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_TOKEN_PENDING_DISPLAY'); ?>
        </div>
    <?php endif; ?>

    <?php if ($this->isCoreAdmin): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_TITLE'); ?></h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    <?php echo Text::_($this->governanceStatus['salt_valid'] ? 'COM_MCPSERVER_GOVERNANCE_SETUP_READY' : 'COM_MCPSERVER_GOVERNANCE_SETUP_DESC'); ?>
                </p>
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
                <?php if (!$this->governanceStatus['salt_valid']): ?>
                    <?php // Fallback only: the salt is provisioned automatically on install/update, so this renders when that did not happen (or the stored salt is unreadable). ?>
                    <form action="index.php?option=com_mcpserver&amp;task=credentials.setup" method="post">
                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_BUTTON'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($this->governedConfigured): ?>

        <?php if ($this->justIssued !== null): ?>
            <div class="alert alert-success" role="alert">
                <h5 class="alert-heading"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_TITLE'); ?></h5>
                <p><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_WARNING'); ?></p>
                <dl class="row mb-0">
                    <dt class="col-sm-2"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_TOKEN'); ?></dt>
                    <dd class="col-sm-10">
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control font-monospace bg-body text-body"
                                id="issuedBearerToken"
                                value="<?php echo htmlspecialchars((string) $this->justIssued['bearer_token'], ENT_QUOTES, 'UTF-8'); ?>"
                                readonly
                                aria-label="<?php echo htmlspecialchars(Text::_('COM_MCPSERVER_CREDENTIALS_ISSUED_TOKEN'), ENT_QUOTES, 'UTF-8'); ?>"
                            >
                            <button class="btn btn-dark" type="button" id="copyIssuedBearerToken">
                                <span class="icon-copy" aria-hidden="true"></span>
                                <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COPY_TOKEN'); ?>
                            </button>
                        </div>
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

        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REQUEST_TITLE'); ?></h5>
            </div>
            <div class="card-body">
                <p class="text-muted small"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REQUEST_DESC'); ?></p>
                <form action="index.php?option=com_mcpserver&amp;task=credentials.requestAccess" method="post">
                    <div class="mb-3">
                        <label class="form-label" for="client_name"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLIENT_NAME_LABEL'); ?></label>
                        <input type="text" class="form-control" id="client_name" name="client_name" required maxlength="255" autocomplete="off">
                        <div class="form-text"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLIENT_NAME_DESC'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REQUEST_BUTTON'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </div>

        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REQUESTS_TITLE'); ?></h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($this->requests)): ?>
                    <p class="text-muted p-3 mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REQUESTS_NO_DATA'); ?></p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th class="ps-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLIENT_NAME_LABEL'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_STATUS'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_EXPIRES'); ?></th><th class="pe-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_ACTIONS'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($this->requests as $request): ?>
                                    <tr>
                                        <td class="ps-3"><?php echo htmlspecialchars($request['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(Text::_('COM_MCPSERVER_CREDENTIALS_REQUEST_STATUS_' . strtoupper($request['status'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php
                                            // 0 is SQL NULL. On a decided request that means the
                                            // approver granted a non-expiring credential; while still
                                            // pending it just means no expiry has been chosen yet.
                                            if ($request['credential_expires'] > 0) {
                                                echo htmlspecialchars(HTMLHelper::_('date', $request['credential_expires'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');
                                            } elseif ($request['status'] === 'requested' || $request['status'] === 'rejected') {
                                                echo Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_PENDING');
                                            } else {
                                                echo Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_NEVER');
                                            }
                                        ?></td>
                                        <td class="pe-3">
                                            <?php if ($request['status'] === 'approved'): ?>
                                                <form action="index.php?option=com_mcpserver&amp;task=credentials.claim" method="post">
                                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($request['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <label class="visually-hidden" for="api_token_<?php echo (int) $request['id']; ?>"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_API_TOKEN_LABEL'); ?></label>
                                                    <div class="input-group input-group-sm">
                                                        <?php // autocomplete="new-password" (not "off", which browsers ignore on password inputs) stops the password manager offering the saved admin login here; the data-*-ignore pairs do the same for 1Password/LastPass/Bitwarden. ?>
                                                        <input type="password" class="form-control" id="api_token_<?php echo (int) $request['id']; ?>" name="api_token" required autocomplete="new-password" data-1p-ignore data-lpignore="true" data-bwignore placeholder="<?php echo Text::_('COM_MCPSERVER_CREDENTIALS_API_TOKEN_LABEL'); ?>">
                                                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLAIM_BUTTON'); ?></button>
                                                    </div>
                                                    <div class="form-text">
                                                        <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLAIM_HELP'); ?>
                                                        <a href="<?php echo htmlspecialchars($this->accountEditUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_OPEN_ACCOUNT_BUTTON'); ?>
                                                        </a>
                                                    </div>
                                                    <?php echo HTMLHelper::_('form.token'); ?>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($this->isCoreAdmin): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header"><h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_PENDING_TITLE'); ?></h5></div>
                <div class="card-body p-0">
                    <?php if (empty($this->pendingRequests)): ?>
                        <p class="text-muted p-3 mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_PENDING_NO_DATA'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-hover mb-0">
                            <thead><tr><th class="ps-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_USER'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLIENT_NAME_LABEL'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_EXPIRY'); ?></th><th class="pe-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_ACTIONS'); ?></th></tr></thead>
                            <tbody><?php foreach ($this->pendingRequests as $request): ?><tr>
                                <td class="ps-3">
                                    <?php if (($request['username'] ?? null) !== null): ?>
                                        <?php // Username is the login, so it identifies the account exactly;
                                              // the display name can be duplicated across users. The id stays
                                              // visible because approving is a security decision and it is the
                                              // identifier the audit trail records. ?>
                                        <?php echo htmlspecialchars($request['username'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (($request['user_name'] ?? null) !== null && $request['user_name'] !== $request['username']): ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($request['user_name'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                        <?php endif; ?>
                                        <span class="d-block text-muted small">#<?php echo (int) $request['user_id']; ?></span>
                                        <?php
                                            // Flag a request that could never be claimed: the requester
                                            // has no route to a Joomla API token, and only a Super User
                                            // can grant them one.
                                            $availability = $this->apiTokenAvailability[(int) $request['user_id']] ?? null;
                                        ?>
                                        <?php if ($availability !== null && !$availability['can_create']): ?>
                                            <div class="alert alert-warning py-1 px-2 mt-1 mb-0 small" role="alert">
                                                <?php if (!$availability['plugin_enabled']): ?>
                                                    <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_TOKEN_PLUGIN_DISABLED'); ?>
                                                <?php else: ?>
                                                    <?php echo Text::sprintf(
                                                        'COM_MCPSERVER_CREDENTIALS_TOKEN_GROUP_BLOCKED',
                                                        htmlspecialchars(
                                                            implode(', ', $availability['group_names']),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        )
                                                    ); ?>
                                                <?php endif; ?>
                                                <?php // Only when the plugin is actually installed; there is
                                                      // nothing to edit otherwise. ?>
                                                <?php if ($availability['extension_id'] !== null): ?>
                                                    <?php
                                                        // color:inherit inline, deliberately.
                                                        //
                                                        // Atum styles links inside a table body
                                                        //   .table tbody a:not(.badge):not(.btn):not(.dropdown-item)
                                                        // at (0,4,2), which outranks .alert-link, and the exact
                                                        // custom properties differ between Joomla/Atum versions —
                                                        // so a rule naming one of them is both a specificity fight
                                                        // and a guess. An inline style beats every selector, and
                                                        // inheriting the alert's own text colour is readable on the
                                                        // alert's own background by construction, in either theme,
                                                        // without knowing any variable name. The underline keeps it
                                                        // identifiable as a link.
                                                    ?>
                                                    <a class="alert-link d-inline-block mt-1 fw-bold"
                                                       style="color: inherit; text-decoration: underline;"
                                                       href="<?php echo Route::_(
                                                        'index.php?option=com_plugins&task=plugin.edit&extension_id='
                                                        . (int) $availability['extension_id']
                                                    ); ?>">
                                                        <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_OPEN_TOKEN_PLUGIN'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php // LEFT JOIN found no user: the account was deleted after
                                              // requesting. Say so rather than showing a bare number. ?>
                                        <span class="text-danger"><?php echo Text::sprintf('COM_MCPSERVER_CREDENTIALS_USER_MISSING', (int) $request['user_id']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($request['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php $approveFormId = 'approve_' . (int) $request['id']; ?>
                                <td>
                                    <?php // The expiry controls sit in their own column but must still submit
                                          // with the approve form in the next cell. A form cannot span table
                                          // cells, so they are bound to it by id via the HTML form attribute. ?>
                                    <div class="d-flex flex-column gap-1">
                                        <?php // No visible label or unit here: the column header carries both.
                                              // aria-label still names the field, which a bare number box read
                                              // out of its column context would otherwise lack. ?>
                                        <input
                                            type="number"
                                            class="form-control form-control-sm js-expires-days"
                                            id="expires_days_<?php echo (int) $request['id']; ?>"
                                            name="expires_days"
                                            form="<?php echo $approveFormId; ?>"
                                            aria-label="<?php echo htmlspecialchars(Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_DAYS_LABEL'), ENT_QUOTES, 'UTF-8'); ?>"
                                            value="365"
                                            min="1"
                                            max="3650"
                                            style="max-width: 90px;"
                                        >
                                        <div class="form-check form-check-sm mb-0">
                                            <input
                                                class="form-check-input js-never-expires"
                                                type="checkbox"
                                                value="1"
                                                id="never_expires_<?php echo (int) $request['id']; ?>"
                                                name="never_expires"
                                                form="<?php echo $approveFormId; ?>"
                                                data-days-field="expires_days_<?php echo (int) $request['id']; ?>"
                                            >
                                            <label class="form-check-label small" for="never_expires_<?php echo (int) $request['id']; ?>">
                                                <?php echo Text::_('COM_MCPSERVER_CREDENTIALS_NEVER_EXPIRES_LABEL'); ?>
                                            </label>
                                        </div>
                                    </div>
                                </td>
                                <td class="pe-3"><div class="d-flex gap-2">
                                    <form action="index.php?option=com_mcpserver&amp;task=credentials.approve" method="post" id="<?php echo $approveFormId; ?>">
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($request['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn btn-sm btn-success"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_APPROVE_BUTTON'); ?></button>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                    </form>
                                    <form action="index.php?option=com_mcpserver&amp;task=credentials.reject" method="post">
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($request['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REJECT_BUTTON'); ?></button>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                    </form>
                                </div></td>
                            </tr><?php endforeach; ?></tbody>
                        </table></div>
                        <script>
                            // Purely a visual cue: the day count is ignored server-side
                            // when never_expires is submitted, so this still behaves
                            // correctly with JavaScript disabled.
                            document.querySelectorAll('.js-never-expires').forEach(function (box) {
                                var sync = function () {
                                    var days = document.getElementById(box.dataset.daysField);
                                    if (days) {
                                        days.disabled = box.checked;
                                    }
                                };
                                box.addEventListener('change', sync);
                                sync();
                            });
                        </script>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4 shadow-sm">
                <div class="card-header"><h5 class="mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_ADMIN_LIST_TITLE'); ?></h5></div>
                <div class="card-body p-0">
                    <?php if (empty($this->adminCredentials)): ?>
                        <p class="text-muted p-3 mb-0"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_NO_DATA'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-hover mb-0">
                            <thead><tr><th class="ps-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_NAME'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_SELECTOR'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_EXPIRES'); ?></th><th><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_STATUS'); ?></th><th class="pe-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_COL_ACTIONS'); ?></th></tr></thead>
                            <tbody><?php foreach ($this->adminCredentials as $credential): ?><tr>
                                <td class="ps-3"><?php echo htmlspecialchars($credential['owner_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars($credential['selector'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo $credential['expires_at'] > 0
                                        ? htmlspecialchars(HTMLHelper::_('date', $credential['expires_at'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8')
                                        : Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_NEVER'); ?></td>
                                <td><?php echo $credential['revoked'] ? Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_REVOKED') : Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_ACTIVE'); ?></td>
                                <td class="pe-3"><?php if (!$credential['revoked']): ?><form action="index.php?option=com_mcpserver&amp;task=credentials.revoke" method="post"><input type="hidden" name="id" value="<?php echo htmlspecialchars($credential['id'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_BUTTON'); ?></button><?php echo HTMLHelper::_('form.token'); ?></form><?php else: ?><form action="index.php?option=com_mcpserver&amp;task=credentials.delete" method="post"><input type="hidden" name="id" value="<?php echo htmlspecialchars($credential['id'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="btn btn-sm btn-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_DELETE_BUTTON'); ?></button><?php echo HTMLHelper::_('form.token'); ?></form><?php endif; ?></td>
                            </tr><?php endforeach; ?></tbody>
                        </table></div>
                    <?php endif; ?>
                </div>
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
                                <?php // The owner of every row here is the viewing user, so their own name says nothing; the client name they gave when requesting is what tells two credentials apart. ?>
                                <th class="ps-3"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_CLIENT_NAME_LABEL'); ?></th>
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
                                    <td class="ps-3"><?php
                                        // Blank for a credential with no surviving request row, which
                                        // the selector column still identifies unambiguously.
                                        echo $credential['client_name'] !== ''
                                            ? htmlspecialchars($credential['client_name'], ENT_QUOTES, 'UTF-8')
                                            : '<span class="text-muted">&mdash;</span>';
                                    ?></td>
                                    <td><code><?php echo htmlspecialchars($credential['selector'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars(HTMLHelper::_('date', $credential['created_at'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $credential['expires_at'] > 0
                                        ? htmlspecialchars(HTMLHelper::_('date', $credential['expires_at'], 'Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8')
                                        : Text::_('COM_MCPSERVER_CREDENTIALS_EXPIRES_NEVER'); ?></td>
                                    <td>
                                        <?php if ($credential['revoked']): ?>
                                            <span class="badge bg-secondary"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_REVOKED'); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_STATUS_ACTIVE'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3">
                                        <?php if (!$credential['revoked']): ?>
                                            <form action="index.php?option=com_mcpserver&amp;task=credentials.revoke" method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_CONFIRM')), ENT_QUOTES, 'UTF-8'); ?>);">
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $credential['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_BUTTON'); ?></button>
                                                <?php echo HTMLHelper::_('form.token'); ?>
                                            </form>
                                        <?php elseif ($this->isCoreAdmin): ?>
                                            <form action="index.php?option=com_mcpserver&amp;task=credentials.delete" method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(Text::_('COM_MCPSERVER_CREDENTIALS_DELETE_CONFIRM')), ENT_QUOTES, 'UTF-8'); ?>);">
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
