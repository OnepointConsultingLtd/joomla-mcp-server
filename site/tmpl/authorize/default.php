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
use Joomla\CMS\Uri\Uri;

/** @var \Joomla\Component\Mcpserver\Site\View\Authorize\HtmlView $this */

/** @var \Joomla\Component\Mcpserver\Administrator\Service\OAuthAuthorizationRequest $request */
$request = $this->oauth['request'];
$credentials = $this->oauth['credentials'];
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="com-mcpserver-oauth">
    <h1><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_HEADING', $e($request->clientName)); ?></h1>

    <p><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_SIGNED_IN_AS', $e($this->oauth['userName'])); ?></p>

    <div class="alert alert-warning">
        <p><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_UNVERIFIED_NAME', $e($request->clientName)); ?></p>
        <?php if ($request->verifiedClientHost() !== null): ?>
            <p><?php echo Text::sprintf('COM_MCPSERVER_OAUTH_VERIFIED_HOST', '<strong>' . $e($request->verifiedClientHost()) . '</strong>'); ?></p>
        <?php endif; ?>
        <p class="mb-0">
            <?php echo Text::sprintf('COM_MCPSERVER_OAUTH_REDIRECT_TARGET', '<strong>' . $e($request->redirectTarget()) . '</strong>'); ?>
        </p>
    </div>

    <form action="<?php echo $e($this->oauth['action']); ?>" method="post">
        <?php if ($credentials === []): ?>
            <div class="alert alert-info">
                <?php echo Text::sprintf(
                    'COM_MCPSERVER_OAUTH_NO_CREDENTIALS',
                    $e(Uri::root() . 'administrator/index.php?option=com_mcpserver&view=credentials')
                ); ?>
            </div>
        <?php else: ?>
            <p><?php echo Text::_('COM_MCPSERVER_OAUTH_EXPLAIN'); ?></p>

            <fieldset class="mb-3">
                <legend class="h5"><?php echo Text::_('COM_MCPSERVER_OAUTH_CHOOSE_CREDENTIAL'); ?></legend>
                <?php foreach ($credentials as $i => $credential): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="credential_id"
                               id="mcp-credential-<?php echo (int) $credential['id']; ?>"
                               value="<?php echo (int) $credential['id']; ?>"<?php echo $i === 0 ? ' checked' : ''; ?> required>
                        <label class="form-check-label" for="mcp-credential-<?php echo (int) $credential['id']; ?>">
                            <?php echo $e($credential['name']); ?>
                            <?php if ($credential['expires'] !== null): ?>
                                <small class="text-muted">
                                    (<?php echo Text::sprintf('COM_MCPSERVER_OAUTH_CREDENTIAL_EXPIRES', HTMLHelper::_('date', $credential['expires']->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC4'))); ?>)
                                </small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <p class="small text-muted"><?php echo Text::_('COM_MCPSERVER_OAUTH_REVOKE_HINT'); ?></p>
        <?php endif; ?>

        <input type="hidden" name="pending" value="<?php echo $e($this->oauth['pendingId']); ?>">
        <?php echo HTMLHelper::_('form.token'); ?>

        <div class="d-flex gap-2">
            <?php if ($credentials !== []): ?>
                <button type="submit" name="decision" value="approve" class="btn btn-primary">
                    <?php echo Text::_('COM_MCPSERVER_OAUTH_APPROVE'); ?>
                </button>
            <?php endif; ?>
            <button type="submit" name="decision" value="deny" class="btn btn-secondary" formnovalidate>
                <?php echo Text::_('COM_MCPSERVER_OAUTH_DENY'); ?>
            </button>
        </div>
    </form>
</div>
