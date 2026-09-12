<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditRetentionService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceSetupService;
use Joomla\Registry\Registry;

/**
 * Bounded credential request and lifecycle UI controller.
 *
 * Every action requires the `mcpserver.credential.self` or `core.manage`
 * component ACL action before it is reached. `core.manage` additionally
 * lets an administrator revoke any credential by id (the credential
 * lifecycle store only exposes per-owner listing, so an administrator's
 * own list still shows only their own credentials).
 */
class CredentialsController extends BaseController
{
    protected $default_view = 'credentials';

    private const MIN_EXPIRES_DAYS = 1;
    private const MAX_EXPIRES_DAYS = 3650;
    private const DEFAULT_EXPIRES_DAYS = 30;

    public function display($cachable = false, $urlparams = array())
    {
        if (!$this->isAuthorised()) {
            $this->setRedirect('index.php?option=com_mcpserver', Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            return false;
        }

        if (!$this->input->getCmd('view')) {
            $this->input->set('view', $this->default_view);
        }

        return parent::display($cachable, $urlparams);
    }

    /** Submit an access request without collecting an API token. */
    public function requestAccess(): void
    {
        if (!$this->isAuthorisedAndTokenValid()) {
            return;
        }

        $user = $this->app->getIdentity();

        try {
            $this->getCredentialRequestService()->request(
                (int) $user->id,
                $this->input->post->getString('client_name', '')
            );
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_CREDENTIALS_REQUEST_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_REQUEST_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /** Approve a different user's request and select that request's expiry. */
    public function approve(): void
    {
        if (!$this->isAuthorisedForSuperUserAndTokenValid('index.php?option=com_mcpserver&view=credentials')) {
            return;
        }

        $user = $this->app->getIdentity();
        $days = max(self::MIN_EXPIRES_DAYS, min(self::MAX_EXPIRES_DAYS, $this->input->post->getInt('expires_days', self::DEFAULT_EXPIRES_DAYS)));
        try {
            $this->getCredentialRequestService()->approve(
                $this->input->post->getString('id', ''),
                (int) $user->id,
                true,
                time() + ($days * 86400)
            );
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_CREDENTIALS_APPROVE_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_APPROVE_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /** Reject a different user's pending request. */
    public function reject(): void
    {
        if (!$this->isAuthorisedForSuperUserAndTokenValid('index.php?option=com_mcpserver&view=credentials')) {
            return;
        }

        $user = $this->app->getIdentity();
        try {
            $this->getCredentialRequestService()->reject($this->input->post->getString('id', ''), (int) $user->id, true);
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_CREDENTIALS_REJECT_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_REJECT_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /** Claim an approved request with the request owner's current Joomla API token. */
    public function claim(): void
    {
        if (!$this->isAuthorisedAndTokenValid()) {
            return;
        }

        $user = $this->app->getIdentity();
        try {
            $result = $this->getCredentialRequestService()->claim(
                $this->input->post->getString('id', ''),
                (int) $user->id,
                (string) $user->username,
                $this->input->post->getString('api_token', '')
            );
            $this->app->setUserState('com_mcpserver.credentials.issued', $result);
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_CLAIM_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /**
     * Revoke a credential. Owners may revoke their own; `core.manage`
     * holders may revoke any credential by id.
     */
    public function revoke(): void
    {
        if (!$this->isAuthorisedAndTokenValid()) {
            return;
        }

        $user = $this->app->getIdentity();
        $id = $this->input->post->getString('id', '');
        $isAdmin = $user->authorise('core.manage', 'com_mcpserver');

        try {
            $this->getCredentialService()->revoke($id, (int) $user->id, $isAdmin);
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_CREDENTIALS_REVOKE_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_REVOKE_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /**
     * Permanently remove a previously revoked credential. Audit rows retain
     * their user and credential selector snapshots for accountability.
     */
    public function delete(): void
    {
        if (!$this->isAuthorisedForSuperUserAndTokenValid('index.php?option=com_mcpserver&view=credentials')) {
            return;
        }

        $id = $this->input->post->getString('id', '');

        try {
            $this->getCredentialService()->delete($id, true);
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_CREDENTIALS_DELETE_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_CREDENTIALS_DELETE_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /**
     * Provision the credential salt (if needed) and enable governed mode.
     * Requires `core.admin` on top of the base credential authorisation,
     * since this mutates component-wide configuration rather than the
     * acting user's own credentials. This is a Credentials-page action
     * (the setup card lives there, before credential requests), so it
     * redirects back to the credentials view.
     */
    public function setup(): void
    {
        if (!$this->isAuthorisedForSetupAndTokenValid()) {
            return;
        }

        $retentionDays = $this->input->post->getInt('metrics_retention_days', 360);

        try {
            $this->getGovernanceSetupService()->enable($retentionDays);
            $this->app->enqueueMessage(Text::_('COM_MCPSERVER_GOVERNANCE_SETUP_SUCCESS'));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_GOVERNANCE_SETUP_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=credentials');
    }

    /**
     * Prune audit rows from #__mcpserver_request_log older than the given
     * retention window. Requires `core.admin`: it mutates governance audit
     * data shared by every user, not the acting user's own state. Prune
     * controls render next to the Dashboard's Recent Requests section, so
     * this redirects back to the dashboard.
     */
    public function prune(): void
    {
        if (!$this->isAuthorisedForPruneAndTokenValid()) {
            return;
        }

        $retentionDays = $this->input->post->getInt('prune_retention_days', self::DEFAULT_EXPIRES_DAYS);

        try {
            $deleted = $this->getGovernanceAuditRetentionService()->prune($retentionDays);
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_SUCCESS', $deleted));
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_ERROR', $e->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_mcpserver&view=dashboard');
    }

    private function isAuthorised(): bool
    {
        $user = $this->app->getIdentity();

        return $user !== null
            && (
                $user->authorise('mcpserver.credential.self', 'com_mcpserver')
                || $user->authorise('core.manage', 'com_mcpserver')
            );
    }

    private function isAuthorisedAndTokenValid(): bool
    {
        if (!$this->isAuthorised()) {
            $this->setRedirect('index.php?option=com_mcpserver', Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            return false;
        }

        if (!Session::checkToken('post')) {
            $this->setRedirect('index.php?option=com_mcpserver&view=credentials', Text::_('JINVALID_TOKEN'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Gate for setup(): it is a Credentials-page action (the setup card
     * renders there, before credential requests), so its invalid-token
     * redirect returns to the credentials view.
     */
    private function isAuthorisedForSetupAndTokenValid(): bool
    {
        return $this->isAuthorisedForCoreAdminAndTokenValid('index.php?option=com_mcpserver&view=credentials');
    }

    /**
     * Gate for prune(): its controls render next to the Dashboard's Recent
     * Requests section, so its invalid-token redirect returns there.
     */
    private function isAuthorisedForPruneAndTokenValid(): bool
    {
        return $this->isAuthorisedForCoreAdminAndTokenValid('index.php?option=com_mcpserver&view=dashboard');
    }

    private function isAuthorisedForCoreAdminAndTokenValid(string $invalidTokenRedirect): bool
    {
        $user = $this->app->getIdentity();
        if ($user === null || !$user->authorise('core.admin', 'com_mcpserver')) {
            $this->setRedirect('index.php?option=com_mcpserver', Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            return false;
        }

        if (!Session::checkToken('post')) {
            $this->setRedirect($invalidTokenRedirect, Text::_('JINVALID_TOKEN'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Require Joomla's global core.admin permission, which is the canonical
     * Super User capability. Component-scoped delegated administration is not
     * sufficient to approve, reject, or permanently delete user credentials.
     */
    private function isAuthorisedForSuperUserAndTokenValid(string $invalidTokenRedirect): bool
    {
        $user = $this->app->getIdentity();
        if ($user === null || !$user->authorise('core.admin')) {
            $this->setRedirect('index.php?option=com_mcpserver', Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            return false;
        }

        if (!Session::checkToken('post')) {
            $this->setRedirect($invalidTokenRedirect, Text::_('JINVALID_TOKEN'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Resolve GovernanceSetupService from the DI container, with a direct
     * fallback mirroring the pattern used by the credentials view. The
     * fallback's persist adapter writes directly to the component's own
     * `#__extensions.params` row, the same storage `ComponentHelper::getParams()`
     * reads on the next request; it is not registered in provider.php.
     */
    private function getGovernanceSetupService(): GovernanceSetupService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has(GovernanceSetupService::class)) {
            return $container->get(GovernanceSetupService::class);
        }

        return new GovernanceSetupService(
            static fn (): array => ComponentHelper::getParams('com_mcpserver')->toArray(),
            static function (array $values): void {
                $db = Factory::getDbo();

                $select = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcpserver'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
                $currentParams = $db->setQuery($select)->loadResult();

                $params = new Registry((string) $currentParams);
                foreach ($values as $key => $value) {
                    $params->set($key, $value);
                }

                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote((string) $params))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_mcpserver'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
                $db->setQuery($update)->execute();
            },
            static fn (): string => (string) Factory::getApplication()->get('secret', '')
        );
    }

    /**
     * Resolve CredentialLifecycleService from the DI container, mirroring
     * the pattern used by the dashboard and MCPB controllers. This service
     * cannot be safely constructed directly here: its cipher dependency
     * derives key material from the Joomla application secret and the
     * component's governed-mode salt, which is provider.php's
     * responsibility alone.
     */
    private function getCredentialService(): CredentialLifecycleService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container === null || !$container->has(CredentialLifecycleService::class)) {
            throw new \RuntimeException(Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'));
        }

        return $container->get(CredentialLifecycleService::class);
    }

    private function getCredentialRequestService(): CredentialRequestService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container === null || !$container->has(CredentialRequestService::class)) {
            throw new \RuntimeException(Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'));
        }

        return $container->get(CredentialRequestService::class);
    }

    /**
     * Resolve GovernanceAuditRetentionService from the DI container. This
     * controller never constructs it directly: it is registered in
     * provider.php alongside the other governance services.
     */
    private function getGovernanceAuditRetentionService(): GovernanceAuditRetentionService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container === null || !$container->has(GovernanceAuditRetentionService::class)) {
            throw new \RuntimeException(Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'));
        }

        return $container->get(GovernanceAuditRetentionService::class);
    }
}
