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
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceAuditQueryService;
use Joomla\Component\Mcpserver\Administrator\Service\MetricsService;

/**
 * Metrics dashboard view.
 *
 * The Recent Requests section is the single request-log table on this
 * page: holders of `mcpserver.credential.audit` or `core.manage` see the
 * governed audit rows (with filters and attributable columns such as user
 * id, credential selector, and target/request correlation); everyone else
 * sees MetricsService's plain recent-request rows, with no governed
 * details leaked. Audit retention pruning renders next to this section,
 * gated to `core.admin`. Governed-mode setup status and the credential
 * encryption identity explanation live on the Credentials view instead.
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

        $this->isCoreAdmin = $user !== null && $user->authorise('core.admin', 'com_mcpserver');
        $this->canViewAudit = $user !== null && (
            $user->authorise('mcpserver.credential.audit', 'com_mcpserver')
            || $user->authorise('core.manage', 'com_mcpserver')
        );

        $metrics = $this->getMetricsService();

        // Belt-and-braces cleanup so the log stays within the retention window
        // even on sites that receive little traffic.
        $metrics->prune();

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
     * applied" rather than passed through.
     */
    private function loadAuditRows(object $app): void
    {
        $input = $app->getInput();

        $userId = $input->getInt('audit_user_id', 0) ?: null;
        $toolName = $input->getString('audit_tool_name', '') ?: null;
        $dateFrom = $input->getString('audit_date_from', '') ?: null;
        $dateTo = $input->getString('audit_date_to', '') ?: null;

        $this->auditFilters = [
            'userId'   => $userId,
            'toolName' => $toolName,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
        ];

        try {
            $this->recent = $this->getAuditQueryService()->search($this->auditFilters);
        } catch (\Throwable $e) {
            $this->recent = [];
        }
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
