<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\AuditToolFilterService;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditQueryService;
use Joomla\Component\Mcpserver\Administrator\Service\MetricsService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;

/**
 * Metrics dashboard view.
 *
 * Everything on this page reads #__mcpserver_request_log, and every read is
 * restricted to the acting user's own rows unless they are a Super User
 * (`core.admin`) — see $scopeUserId. That covers the summary cards, the
 * charts, the top tool/method tables, and the Recent Requests table alike:
 * scoping only the table would still expose other users' activity in
 * aggregate.
 *
 * The Recent Requests section is the single request-log table on this
 * page: holders of `mcpserver.credential.audit` or `core.manage` see the
 * governed audit rows (with filters and attributable columns such as user
 * id, credential selector, and target/request correlation); everyone else
 * sees MetricsService's plain recent-request rows, with no governed
 * details leaked. That gate decides which *columns* a viewer gets; the
 * Super User check decides whose *rows* they get. Audit retention pruning
 * renders next to this section, gated to `core.admin`. Governed-mode setup
 * status and the credential encryption identity explanation live on the
 * Credentials view instead.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array */
    public $summary;

    /** @var array */
    public $topTools;

    /** @var array */
    public $topMethods;

    /** @var array */
    public $perDay;

    /**
     * Recent-request rows for the merged Recent Requests table: governed
     * audit rows (array shape) when $canViewAudit is true, else plain
     * MetricsService rows (stdClass shape).
     *
     * @var list<array<string, mixed>>|list<object>
     */
    public $recent;

    /**
     * Set when the audit query failed, so the template can distinguish "the
     * query did not run" from "there are no matching rows". Leaving these
     * indistinguishable would report an empty audit trail as fact.
     *
     * @var string|null
     */
    public $auditError = null;

    /** @var bool */
    public $metricsEnabled;

    /**
     * True when the acting user may prune the audit trail. Requires
     * `core.admin`: pruning mutates shared audit data rather than the
     * acting user's own state.
     *
     * @var bool
     */
    public bool $isCoreAdmin = false;

    /**
     * True when the acting user may view the governed audit trail in
     * Recent Requests: either the dedicated `mcpserver.credential.audit`
     * ACL action or the broader `core.manage`.
     *
     * @var bool
     */
    public bool $canViewAudit = false;

    /**
     * Joomla user id every request-log read on this page is restricted to,
     * or null for the unrestricted Super User view.
     *
     * Requests recorded without attribution (legacy shared-token mode, or
     * authentication failures that never resolved a principal) carry a null
     * `user_id` and therefore belong to nobody: they are visible to Super
     * Users only. The template says so rather than letting a restricted
     * viewer read an empty table as "nothing happened".
     *
     * @var int|null
     */
    public ?int $scopeUserId = null;

    /**
     * Choices for the audit Tool Name filter, taken from the tool and prompt
     * registries so the operator picks from what this build actually exposes
     * instead of typing a name from memory. See AuditToolFilterService for
     * what each group means; all four are empty when the registries could not
     * be read, which the template treats as "fall back to a free-text box".
     *
     * @var array{available: list<string>, prompts: list<string>, disabled: list<string>, unregistered: list<string>}
     */
    public array $toolFilterOptions = [
        'available'    => [],
        'prompts'      => [],
        'disabled'     => [],
        'unregistered' => [],
    ];

    /**
     * Accounts offered in the audit User filter, drawn from the rows that
     * actually exist so every choice returns something. Empty when the
     * viewer is scoped (they are not offered the filter) or the lookup
     * failed, which the template treats as "fall back to a numeric box".
     *
     * `user_name` is null for an account since deleted from Joomla; the
     * template falls back to the id rather than dropping the option, so a
     * departed user's activity stays reachable.
     *
     * @var list<array{user_id: int, user_name: ?string}>
     */
    public array $auditUsers = [];

    /** @var array{userId: ?int, toolName: ?string, dateFrom: ?string, dateTo: ?string} */
    public array $auditFilters = [
        'userId'   => null,
        'toolName' => null,
        'dateFrom' => null,
        'dateTo'   => null,
    ];

    /**
     * Display the view.
     *
     * @param   string  $tpl  Template
     * @return  void
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        // Global core.admin, matching CredentialsController's prune gate: the
        // button must not render for anyone the controller will reject, and
        // pruning destroys shared audit history rather than the user's own.
        $this->isCoreAdmin = $user !== null && $user->authorise('core.admin');
        $this->canViewAudit = $user !== null && (
            $user->authorise('mcpserver.credential.audit', 'com_mcpserver')
            || $user->authorise('core.manage', 'com_mcpserver')
        );

        // Only a Super User may read the whole site's request log. Everyone
        // else — including delegated admins holding core.manage or the
        // dedicated audit action — is restricted to rows attributed to their
        // own account. A missing identity scopes to 0, which matches nothing.
        $this->scopeUserId = $this->isCoreAdmin ? null : (int) ($user?->id ?? 0);

        $metrics = $this->getMetricsService();

        // Belt-and-braces cleanup so the log stays within the retention window
        // even on sites that receive little traffic. Run before scoping:
        // retention applies to the whole log, not to the viewer's own rows.
        $metrics->prune();

        $metrics = $metrics->withUserScope($this->scopeUserId);

        $this->metricsEnabled = $metrics->isEnabled();
        $this->summary        = $metrics->getSummary();
        $this->topTools       = $metrics->getTopTools(10);
        $this->topMethods     = $metrics->getTopMethods(10);
        $this->perDay         = $metrics->getRequestsPerDay(14);

        if ($this->canViewAudit) {
            $this->loadAuditRows($app);
        } else {
            $this->recent = $metrics->getRecentRequests(25);
        }

        ToolbarHelper::title(Text::_('COM_MCPSERVER_DASHBOARD_TITLE'), 'chart');
        ToolbarHelper::preferences('com_mcpserver');

        parent::display($tpl);
    }

    /**
     * Populate the merged Recent Requests table with governed audit rows
     * for audit-capable holders, from the request's GET parameters.
     * Filters are optional; an empty filter value is treated as "not
     * applied" rather than passed through. Whatever the filters say, the
     * query is still confined to $scopeUserId.
     */
    private function loadAuditRows(object $app): void
    {
        $input = $app->getInput();

        // A restricted viewer is not offered the User ID filter and any value
        // they submit is dropped here, so the form never round-trips an id
        // whose rows the query below would refuse to return.
        $userId = $this->scopeUserId === null
            ? ($input->getInt('audit_user_id', 0) ?: null)
            : null;
        $toolName = $input->getString('audit_tool_name', '') ?: null;
        $dateFrom = $input->getString('audit_date_from', '') ?: null;
        $dateTo = $input->getString('audit_date_to', '') ?: null;

        $this->auditFilters = [
            'userId'   => $userId,
            'toolName' => $toolName,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
        ];

        $this->loadToolFilterOptions($toolName);
        $this->loadAuditUsers($userId);

        try {
            $this->recent = $this->getAuditQueryService()
                ->withUserScope($this->scopeUserId)
                ->search($this->auditFilters);
        } catch (\InvalidArgumentException $e) {
            // Bad filter input (e.g. a malformed audit_date_from). Tell the
            // operator their filter was rejected, never that there is no data.
            $this->recent = [];
            $this->auditError = Text::sprintf(
                'COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_INVALID',
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            );
        } catch (\Throwable $e) {
            // An empty result and a failed query must never render identically:
            // reporting "no data" to someone auditing a credential's use would
            // fabricate the absence of evidence.
            $this->recent = [];
            $this->auditError = Text::_('COM_MCPSERVER_GOVERNANCE_AUDIT_UNAVAILABLE');
            Log::add(
                'Governed audit query failed: ' . $e->getMessage(),
                Log::ERROR,
                'com_mcpserver'
            );
        }
    }

    /**
     * Populate the User dropdown for viewers who may see the whole log.
     *
     * A scoped viewer is not offered the filter at all, so the lookup is
     * skipped for them rather than run and discarded. $appliedUserId is
     * carried through when the log holds no rows for it, so the dropdown
     * never silently disagrees with the filter that was applied.
     */
    private function loadAuditUsers(?int $appliedUserId): void
    {
        if ($this->scopeUserId !== null) {
            return;
        }

        try {
            $this->auditUsers = $this->getAuditQueryService()->getAttributedUsers();
        } catch (\Throwable $e) {
            // A dropdown we cannot populate must not take the dashboard down
            // with it; the template falls back to the numeric box.
            Log::add(
                'Could not build the audit user filter list: ' . $e->getMessage(),
                Log::WARNING,
                'com_mcpserver'
            );

            return;
        }

        if ($appliedUserId === null || $this->auditUsers === []) {
            return;
        }

        foreach ($this->auditUsers as $user) {
            if ($user['user_id'] === $appliedUserId) {
                return;
            }
        }

        $this->auditUsers[] = ['user_id' => $appliedUserId, 'user_name' => null];
    }

    /**
     * Populate the Tool Name dropdown. A registry we cannot read must not
     * take the dashboard down with it: the options stay empty and the
     * template falls back to the free-text box this filter used to be.
     */
    private function loadToolFilterOptions(?string $appliedToolName): void
    {
        try {
            $this->toolFilterOptions = $this->getToolFilterService()->getOptions($appliedToolName);
        } catch (\Throwable $e) {
            Log::add(
                'Could not build the audit tool filter list: ' . $e->getMessage(),
                Log::WARNING,
                'com_mcpserver'
            );
        }
    }

    /**
     * Resolve AuditToolFilterService from the DI container, with a direct
     * fallback mirroring the pattern used by the RPC controllers.
     */
    private function getToolFilterService(): AuditToolFilterService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has(AuditToolFilterService::class)) {
            return $container->get(AuditToolFilterService::class);
        }

        return new AuditToolFilterService(
            new ToolRegistry(),
            new PromptRegistry(),
            new PolicyService(ComponentHelper::getParams('com_mcpserver'))
        );
    }

    private function getAuditQueryService(): GovernanceAuditQueryService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container === null || !$container->has(GovernanceAuditQueryService::class)) {
            throw new \RuntimeException(Text::_('COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED'));
        }

        return $container->get(GovernanceAuditQueryService::class);
    }

    /**
     * Resolve MetricsService from the DI container, with a direct fallback
     * mirroring the pattern used by the RPC controllers.
     */
    private function getMetricsService(): MetricsService
    {
        $container = McpserverComponent::getServiceContainer();
        if ($container !== null && $container->has(MetricsService::class)) {
            return $container->get(MetricsService::class);
        }

        return new MetricsService(ComponentHelper::getParams('com_mcpserver'));
    }
}
