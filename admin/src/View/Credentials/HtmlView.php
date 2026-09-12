<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\View\Credentials;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceSetupService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenAvailability;

/**
 * Self-service credential request and lifecycle view. Users request access,
 * then claim an approved request with their own Joomla API token. It never
 * renders a stored/previously issued token; only the token returned by the
 * claim action, held in user state for exactly one redirect, is plaintext.
 *
 * This view also owns governed-mode setup: provisioning the credential
 * salt/retention window and the credential encryption identity
 * explanation, both rendered before credential requests. It does not own
 * the governed audit query: the audit trail and its retention pruning are
 * merged into the Dashboard's Recent Requests section instead.
 */
class HtmlView extends BaseHtmlView
{
    /** @var list<array{id:string,owner_id:int,owner_name:string,client_name:string,selector:string,expires_at:int,created_at:int,revoked:bool}> */
    public $credentials = [];

    /** @var list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string,username:?string,user_name:?string}> */
    public array $requests = [];

    /** @var list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string,username:?string,user_name:?string}> */
    public array $pendingRequests = [];

    /** @var list<array{id:string,owner_id:int,owner_name:string,client_name:string,selector:string,expires_at:int,created_at:int,revoked:bool}> */
    public array $adminCredentials = [];

    /** @var array{id:string,bearer_token:string}|null */
    public $justIssued = null;

    /**
     * True once CredentialLifecycleService can be resolved, i.e. a valid
     * credential salt is provisioned. Governs whether the warning banner or
     * the credential request/list UI is shown; failing closed on any
     * resolution error keeps this page from exposing partially-configured
     * state.
     *
     * @var bool
     */
    public $governedConfigured = false;

    /**
     * Per-requester API token availability, keyed by user id, for the pending
     * approval queue. Only populated for Super Users, who are the only ones
     * who can act on it.
     *
     * @var array<int, array{can_create:bool, plugin_enabled:bool, group_names:list<string>}>
     */
    public array $apiTokenAvailability = [];

    /**
     * Link to the acting user's own account, where the API Tokens tab lives.
     *
     * @var string
     */
    public $accountEditUrl = '';

    /**
     * Message from the failure that cleared $governedConfigured, when the cause
     * was a query/infrastructure error rather than an unprovisioned salt. The
     * two render identically otherwise, which would tell a fully-configured
     * site that governed mode "is not configured" on a momentary DB blip.
     *
     * @var string|null
     */
    public $loadError = null;

    /**
     * True when the acting user may provision the governance salt.
     * Requires `core.admin`: this mutates component-wide configuration
     * rather than the acting user's own state.
     *
     * @var bool
     */
    public bool $isCoreAdmin = false;

    /** @var array{configured:bool,salt_valid:bool,governed_active:bool,recovery_key_fingerprint:?string} */
    public array $governanceStatus = [
        'configured' => false,
        'salt_valid' => false,
        'governed_active' => false,
        'recovery_key_fingerprint' => null,
    ];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        // Governed Mode is the master switch for this entire page. Enforced
        // here as well as in CredentialsController for the same reason as the
        // ACL check below: the view is reachable by `view=` alone.
        if (!(bool) ComponentHelper::getParams('com_mcpserver')->get('governed_mode', 0)) {
            throw new \Exception(Text::_('COM_MCPSERVER_CREDENTIALS_GOVERNED_MODE_OFF'), 403);
        }

        // Defense in depth: Joomla resolves a view by its `view=` GET
        // parameter independent of which controller/task was invoked, so
        // this view is reachable directly (e.g. via DisplayController)
        // even when a request bypasses CredentialsController's own ACL
        // gate. The ACL invariant is only actually guaranteed if it is
        // also enforced here.
        if (
            $user === null
            || (
                !$user->authorise('mcpserver.credential.self', 'com_mcpserver')
                && !$user->authorise('core.manage', 'com_mcpserver')
            )
        ) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Global core.admin, matching every admin gate in CredentialsController
        // (setup, prune, approve, reject, delete). A view that renders controls
        // the controller will refuse is worse than not rendering them.
        $this->isCoreAdmin = $user->authorise('core.admin');

        if ($this->isCoreAdmin) {
            $this->governanceStatus = $this->getGovernanceSetupService()->status();
        }

        $credentialService = null;

        try {
            $credentialService = $this->getCredentialService();
        } catch (\Throwable $e) {
            // The service itself cannot be built: the salt is genuinely
            // unprovisioned. This is the real "not configured" signal.
            $this->governedConfigured = false;
        }

        if ($credentialService !== null) {
            try {
                $this->credentials = $credentialService->listForOwner((int) $user->id);
                $requestService = $this->getCredentialRequestService();
                // A claimed request has already become a credential, and the
                // credential list below is where its lifecycle continues
                // (expiry, revocation, deletion). Listing it here as well gave
                // one credential two rows, the request row being the stale one
                // and the only one with no action attached.
                $this->requests = array_values(array_filter(
                    $requestService->listForUser((int) $user->id),
                    static fn (array $request): bool => $request['status'] !== 'claimed'
                ));
                if ($this->isCoreAdmin) {
                    $this->pendingRequests = $requestService->listPending();
                    $this->adminCredentials = $credentialService->listAllMetadata(true);
                }
                $this->governedConfigured = true;
            } catch (\Throwable $e) {
                // Provisioned, but a query failed. Report it as an error rather
                // than as a configuration state, which would tell an admin their
                // salt is missing on what is really a momentary DB fault. The
                // setup fallback keys off $governanceStatus['salt_valid'] rather
                // than this flag, so it stays hidden here.
                $this->governedConfigured = false;
                $this->loadError = $e->getMessage();
                Log::add(
                    'Credential listing failed: ' . $e->getMessage(),
                    Log::ERROR,
                    'com_mcpserver'
                );
            }
        }

        // com_admin's profile view was removed in Joomla 5, so linking it 404s.
        // This is the route core's own admin user menu uses for "Edit Account",
        // and com_users' Dispatcher::checkAccess() whitelists user.edit on your
        // own id, so it works for requesters with no com_users permission at
        // all. #attrib-joomlatoken opens plg_user_token's fieldset directly;
        // return brings them back here to claim once the token is saved.
        $this->accountEditUrl = 'index.php?option=com_users&task=user.edit&id=' . (int) $user->id
            // urlencode: base64 can contain '+', which a query string decodes
            // as a space, and com_users' BASE64 input filter then strips it and
            // returns the user to the admin home instead of here.
            . '&return=' . urlencode(base64_encode(Uri::base() . 'index.php?option=com_mcpserver&view=credentials'))
            . '#attrib-joomlatoken';

        // Diagnose each pending requester's API token availability for the
        // approver. A requester whose user group is outside plg_user_token's
        // Allowed User Groups can never produce a token and so can never claim
        // — and only a Super User can change that, which is why it is reported
        // here and not on the requester's own page.
        // Never fatal: this is guidance, and the claim itself still validates.
        if ($this->pendingRequests !== []) {
            try {
                $container = McpserverComponent::getServiceContainer();
                if ($container !== null && $container->has(JoomlaApiTokenAvailability::class)) {
                    $availability = $container->get(JoomlaApiTokenAvailability::class);
                    foreach ($this->pendingRequests as $pending) {
                        $this->apiTokenAvailability[(int) $pending['user_id']] =
                            $availability->forUser((int) $pending['user_id']);
                    }
                }
            } catch (\Throwable $e) {
                $this->apiTokenAvailability = [];
            }
        }

        // Shown exactly once, but only consumed once it will actually be
        // rendered: the plaintext bearer token exists nowhere else, so clearing
        // it on a page that cannot display it destroys it permanently.
        $this->justIssued = $app->getUserState('com_mcpserver.credentials.issued');

        if ($this->justIssued !== null && $this->governedConfigured) {
            $app->setUserState('com_mcpserver.credentials.issued', null);
        }

        ToolbarHelper::title(Text::_('COM_MCPSERVER_CREDENTIALS_TITLE'), 'key');

        // This page has no submenu entry — it is conditional on Governed Mode,
        // which the administrator Components menu cannot express — so without an
        // explicit way back the only route off it is the global Components
        // dropdown. An explicit href rather than the default
        // javascript:history.back(), which would return to whatever page
        // happened to precede this one (often com_config after a save).
        ToolbarHelper::back(
            Text::_('COM_MCPSERVER_SUBMENU_CLIENT_CONFIG'),
            'index.php?option=com_mcpserver&view=mcpcomponent'
        );

        // Match the other two views, but only for users who can actually use
        // it: this page is self-service, so a non-admin holder of
        // mcpserver.credential.self must not be offered a component-wide
        // configuration button they cannot open.
        if ($this->isCoreAdmin) {
            ToolbarHelper::preferences('com_mcpserver');
        }

        parent::display($tpl);
    }

    /**
     * Resolve CredentialLifecycleService from the DI container. This
     * component cannot be safely constructed directly here: its cipher
     * dependency derives key material from the Joomla application secret
     * and the component's governed-mode salt, which is provider.php's
     * responsibility alone. A resolution failure (e.g. no salt provisioned
     * yet) is the credentials-domain signal that governed mode is not yet
     * usable.
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
     * Resolve GovernanceSetupService from the DI container, with a direct
     * fallback mirroring the pattern used by CredentialsController. The
     * fallback's persist adapter is a no-op here: this view only reads
     * setup status, it never persists configuration itself.
     */
    private function getGovernanceSetupService(): GovernanceSetupService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has(GovernanceSetupService::class)) {
            return $container->get(GovernanceSetupService::class);
        }

        $params = ComponentHelper::getParams('com_mcpserver');

        return new GovernanceSetupService(
            static fn (): array => $params->toArray(),
            static function (array $values): void {
                // No-op fallback: the view never persists configuration itself.
            },
            static fn (): string => (string) Factory::getApplication()->get('secret', ''),
            // The view only ever calls status(), never enable(), so the
            // credential count is never consulted on this path.
            static fn (): int => 0
        );
    }
}
