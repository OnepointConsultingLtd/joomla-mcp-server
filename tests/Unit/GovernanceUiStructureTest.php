<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * Source-structure regression tests for the Credentials/Dashboard split.
 *
 * A full MVC display() harness (real Joomla application, ACL, DI container,
 * database) is impractical for this admin UI in a unit-test environment, so
 * these tests assert section ownership, section order, and ACL-gate wiring
 * directly against the shipped source: which services each view resolves,
 * which template sections exist in each screen, the relative order those
 * sections render in, and which controller redirects each governance action
 * uses. This is not a substitute for the existing service-level unit tests
 * (GovernanceSetupServiceTest, GovernanceAuditQueryServiceTest, etc.), which
 * already cover business-logic behavior.
 *
 * Current ownership: the Credentials view/template own governed-mode setup
 * (credential salt provisioning, retention window, encryption identity
     * fingerprint), rendered before credential requests; setup() redirects back
 * to credentials. The Dashboard's Recent Requests section is the single
 * request-log table: it merges governed audit filters/columns (User ID,
 * credential selector, target) for `mcpserver.credential.audit`/`core.manage`
 * holders, and otherwise shows plain MetricsService rows with no governed
 * details leaked. Audit retention pruning renders inside that same section,
 * gated to `core.admin`, and prune() redirects back to the dashboard.
 */
class GovernanceUiStructureTest extends TestCase
{
    private const ADMIN_ROOT = __DIR__ . '/../../admin';

    private function credentialsViewSource(): string
    {
        return (string) file_get_contents(self::ADMIN_ROOT . '/src/View/Credentials/HtmlView.php');
    }

    private function credentialsTemplateSource(): string
    {
        return (string) file_get_contents(self::ADMIN_ROOT . '/tmpl/credentials/default.php');
    }

    private function dashboardViewSource(): string
    {
        return (string) file_get_contents(self::ADMIN_ROOT . '/src/View/Dashboard/HtmlView.php');
    }

    private function dashboardTemplateSource(): string
    {
        return (string) file_get_contents(self::ADMIN_ROOT . '/tmpl/dashboard/default.php');
    }

    private function controllerSource(): string
    {
        return (string) file_get_contents(self::ADMIN_ROOT . '/src/Controller/CredentialsController.php');
    }

    public function testDashboardViewDoesNotResolveGovernanceSetupService(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertStringNotContainsString(
            'GovernanceSetupService',
            $source,
            'Dashboard view must not resolve governance setup state; that is Credentials-owned.'
        );
        $this->assertStringNotContainsString('$governanceStatus', $source);
    }

    public function testDashboardViewResolvesGovernanceAuditQueryService(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertStringContainsString(
            'GovernanceAuditQueryService',
            $source,
            'Dashboard view must resolve the governance audit query service to merge into Recent Requests.'
        );
    }

    public function testDashboardViewGatesAuditWithManageOrDedicatedAuditAction(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertMatchesRegularExpression(
            "/canViewAudit\\s*=.*mcpserver\\.credential\\.audit.*core\\.manage/s",
            $source,
            'Dashboard must gate audit visibility behind mcpserver.credential.audit or core.manage.'
        );
    }

    public function testDashboardViewGatesPruneWithGlobalCoreAdmin(): void
    {
        $source = $this->dashboardViewSource();

        // Global core.admin, not core.admin on com_mcpserver: pruning destroys
        // the shared audit trail and must match CredentialsController's gate,
        // so the button never renders for a user the controller will refuse.
        $this->assertMatchesRegularExpression(
            "/isCoreAdmin\\s*=.*authorise\\('core\\.admin'\\)/s",
            $source,
            'Dashboard must gate prune visibility behind global core.admin.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/isCoreAdmin\\s*=.*authorise\\('core\\.admin', 'com_mcpserver'\\)/s",
            $source,
            'Prune must not be delegable via component-scoped core.admin.'
        );
    }

    public function testDashboardViewFallsBackToMetricsRowsWhenNotAuditCapable(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertStringContainsString('getRecentRequests', $source);
        $this->assertMatchesRegularExpression(
            '/canViewAudit\)\s*\{.*?\}\s*else\s*\{.*?getRecentRequests/s',
            $source,
            'Dashboard must use plain MetricsService recent rows when the acting user is not audit-capable.'
        );
    }

    public function testDashboardViewRestrictsRowsToTheActingUserUnlessSuperUser(): void
    {
        $source = $this->dashboardViewSource();

        // Global core.admin is Joomla's Super User check. Anyone else — a
        // delegated admin with core.manage or the dedicated audit action
        // included — is pinned to their own user id.
        $this->assertMatchesRegularExpression(
            '/scopeUserId\s*=\s*\$this->isCoreAdmin\s*\?\s*null\s*:/s',
            $source,
            'Dashboard must read the whole request log only for a Super User.'
        );
        $this->assertMatchesRegularExpression(
            '/scopeUserId\s*=.*\$user\?->id/s',
            $source,
            'A non-Super-User must be scoped to their own Joomla user id.'
        );
    }

    public function testDashboardViewScopesBothTheMetricsAndAuditReads(): void
    {
        $source = $this->dashboardViewSource();

        // Scoping only the Recent Requests table would still expose other
        // users' activity through the summary cards, charts and top tables,
        // which read the same log.
        $this->assertMatchesRegularExpression(
            '/\$metrics\s*=\s*\$metrics->withUserScope\(\$this->scopeUserId\)/',
            $source,
            'The metrics reads behind the cards, charts and top tables must be user-scoped.'
        );
        $this->assertMatchesRegularExpression(
            '/getAuditQueryService\(\)\s*->withUserScope\(\$this->scopeUserId\)/s',
            $source,
            'The governed audit rows must be user-scoped.'
        );
    }

    public function testDashboardViewPrunesBeforeScopingSoRetentionStaysSiteWide(): void
    {
        $source = $this->dashboardViewSource();

        $prunePos = strpos($source, '$metrics->prune()');
        $scopePos = strpos($source, '$metrics->withUserScope(');

        $this->assertIsInt($prunePos, 'Dashboard must still run the belt-and-braces retention prune.');
        $this->assertIsInt($scopePos, 'Dashboard must scope the metrics service.');
        $this->assertLessThan(
            $scopePos,
            $prunePos,
            'Retention trims the whole log, so prune() must run on the unscoped service.'
        );
    }

    public function testDashboardViewIgnoresTheUserIdFilterForRestrictedViewers(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertMatchesRegularExpression(
            '/\$userId\s*=\s*\$this->scopeUserId\s*===\s*null\s*\?\s*\(\$input->getInt\(\'audit_user_id\'/s',
            $source,
            'A restricted viewer must not be able to submit an audit_user_id filter.'
        );
    }

    public function testDashboardTemplateOffersTheUserIdFilterOnlyToUnscopedViewers(): void
    {
        $source = $this->dashboardTemplateSource();

        $filterPos = strpos($source, "name=\"audit_user_id\"");
        $this->assertIsInt($filterPos, 'Dashboard template must still offer the User ID filter.');

        $gatePos = strrpos(substr($source, 0, $filterPos), '$this->scopeUserId === null');
        $this->assertIsInt(
            $gatePos,
            'The User ID filter must be gated on the viewer reading the whole log.'
        );
    }

    public function testDashboardTemplateTellsRestrictedViewersWhatTheyAreNotSeeing(): void
    {
        $source = $this->dashboardTemplateSource();

        // An empty scoped table must never read as "nothing happened on this
        // site" — the page simply never queried anyone else's rows.
        $this->assertStringContainsString('COM_MCPSERVER_DASHBOARD_SCOPE_OWN_ONLY', $source);
        $this->assertStringContainsString('COM_MCPSERVER_DASHBOARD_NO_DATA_OWN', $source);
    }

    public function testDashboardViewBuildsTheToolFilterFromTheRegistry(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertStringContainsString(
            'AuditToolFilterService',
            $source,
            'The Tool Name filter must be populated from the registered tools, not typed free-hand.'
        );
        $this->assertMatchesRegularExpression(
            '/loadToolFilterOptions\(\$toolName\)/',
            $source,
            'The applied tool filter must be passed in so an unregistered value survives into the dropdown.'
        );
    }

    public function testDashboardTemplateRendersTheToolFilterAsADropdownWithAFreeTextFallback(): void
    {
        $source = $this->dashboardTemplateSource();

        $this->assertStringContainsString(
            'name="audit_tool_name"',
            $source,
            'The Tool Name filter must keep its request parameter so existing filter URLs still work.'
        );
        $this->assertStringContainsString('<select class="form-select" id="audit_tool"', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_ANY', $source);

        // The filtered column holds prompt names as well as tool names, so the
        // dropdown has to offer both or prompts/get rows become unfilterable.
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_TOOL_PROMPTS', $source);
        $this->assertStringContainsString("\$toolOptions['prompts']", $source);

        // A registry that could not be read must leave a usable filter behind
        // rather than a dropdown with nothing in it.
        $this->assertMatchesRegularExpression(
            '/\$toolFilterGroups === \[\]\) :.*?<input type="text".*?name="audit_tool_name"/s',
            $source,
            'An empty tool list must fall back to the free-text box.'
        );
    }

    public function testCredentialsViewDoesNotResolveGovernanceAuditServices(): void
    {
        $source = $this->credentialsViewSource();

        $this->assertStringNotContainsString(
            'GovernanceAuditQueryService',
            $source,
            'Credentials view must not resolve the governance audit query service; that is Dashboard-owned.'
        );
        $this->assertStringNotContainsString(
            'GovernanceAuditRetentionService',
            $source,
            'Credentials view must not resolve the governance audit retention service; that is Dashboard-owned.'
        );
        $this->assertStringNotContainsString('$auditRows', $source);
        $this->assertStringNotContainsString('$auditFilters', $source);
        $this->assertStringNotContainsString('canViewAudit', $source);
    }

    public function testCredentialsViewResolvesGovernanceSetupService(): void
    {
        $source = $this->credentialsViewSource();

        $this->assertStringContainsString(
            'GovernanceSetupService',
            $source,
            'Credentials view must resolve governance setup state so the setup card can render.'
        );
        $this->assertStringContainsString('$governanceStatus', $source);
    }

    public function testCredentialsViewGatesSetupWithCoreAdmin(): void
    {
        $source = $this->credentialsViewSource();

        $this->assertMatchesRegularExpression(
            "/isCoreAdmin\\s*=.*authorise\\('core\\.admin'\\)/s",
            $source,
            'Credentials view must gate governance setup status behind core.admin.'
        );
    }

    public function testCredentialsTemplateSetupCardRendersBeforeCredentialRequestCard(): void
    {
        $source = $this->credentialsTemplateSource();

        $setupPos = strpos($source, 'COM_MCPSERVER_GOVERNANCE_SETUP_TITLE');
        $requestPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_REQUEST_TITLE');

        $this->assertIsInt($setupPos, 'Credentials template must render the governance setup section.');
        $this->assertIsInt($requestPos, 'Credentials template must render the credential-request section.');

        $this->assertLessThan($requestPos, $setupPos, 'Governed Mode Setup must render before Request a Credential.');
    }

    public function testCredentialsTemplateSetupFormPostsToCredentialsSetupTask(): void
    {
        $source = $this->credentialsTemplateSource();

        $this->assertStringContainsString('task=credentials.setup', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_SETUP_BUTTON', $source);
    }

    public function testCredentialsTemplateRendersSetupFormOnlyWhenSaltIsMissing(): void
    {
        $source = $this->credentialsTemplateSource();

        // The salt is provisioned automatically on install/update, so the manual
        // form is a recovery affordance. Rendering it unconditionally invites a
        // Super User to press it on a healthy site, which is the one action the
        // service has to refuse to avoid orphaning stored credentials.
        $formPos = strpos($source, 'task=credentials.setup');
        $this->assertIsInt($formPos, 'Credentials template must still offer the manual setup fallback.');

        $gatePos = strrpos(substr($source, 0, $formPos), "!\$this->governanceStatus['salt_valid']");
        $this->assertIsInt(
            $gatePos,
            'The manual setup form must be gated on the salt being missing or unreadable.'
        );
    }

    public function testCredentialsTemplateDoesNotContainGovernanceAuditSection(): void
    {
        $source = $this->credentialsTemplateSource();

        $this->assertStringNotContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_TITLE', $source);
        $this->assertStringNotContainsString('credentials.prune', $source);
    }

    public function testCredentialsTemplateUsesRowActionsWithoutSeparateAdminRevokePanel(): void
    {
        $source = $this->credentialsTemplateSource();

        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_REVOKE_BUTTON', $source);
        $this->assertStringNotContainsString('COM_MCPSERVER_CREDENTIALS_ADMIN_REVOKE_TITLE', $source);
        $this->assertStringNotContainsString('admin_revoke_id', $source);
    }

    public function testCredentialsTemplateOrdersWarningThenSetupThenIssuedTokenThenRequestWorkflowThenList(): void
    {
        $source = $this->credentialsTemplateSource();

        $warningPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED');
        $setupPos = strpos($source, 'COM_MCPSERVER_GOVERNANCE_SETUP_TITLE');
        $issuedPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_ISSUED_TITLE');
        $requestPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_REQUEST_TITLE');
        $ownRequestsPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_REQUESTS_TITLE');
        $pendingPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_PENDING_TITLE');
        $listPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_LIST_TITLE');

        $this->assertIsInt($warningPos);
        $this->assertIsInt($setupPos);
        $this->assertIsInt($issuedPos);
        $this->assertIsInt($requestPos);
        $this->assertIsInt($ownRequestsPos);
        $this->assertIsInt($pendingPos);
        $this->assertIsInt($listPos);

        $this->assertLessThan($setupPos, $warningPos, 'Warning must render first.');
        $this->assertLessThan($issuedPos, $setupPos, 'Governed Mode Setup must render before the one-time issued token.');
        $this->assertLessThan($requestPos, $issuedPos, 'The one-time issued token must render before the request form.');
        $this->assertLessThan($ownRequestsPos, $requestPos, 'Request form must render before the request list.');
        $this->assertLessThan($pendingPos, $ownRequestsPos, 'Own requests must render before the Super User pending queue.');
        $this->assertLessThan($listPos, $pendingPos, 'The pending queue must render before the credential list.');
    }

    public function testDashboardTemplateDoesNotContainSeparateGovernanceCards(): void
    {
        $source = $this->dashboardTemplateSource();

        $this->assertStringNotContainsString('COM_MCPSERVER_GOVERNANCE_SETUP_TITLE', $source);
        $this->assertStringNotContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_TITLE', $source);
        $this->assertStringNotContainsString('credentials.setup', $source);
    }

    public function testDashboardTemplateHasSingleRecentRequestsTableContainer(): void
    {
        $source = $this->dashboardTemplateSource();

        $occurrences = substr_count($source, 'COM_MCPSERVER_DASHBOARD_RECENT');

        $this->assertSame(
            1,
            $occurrences,
            'Dashboard template must render exactly one Recent Requests section header (no duplicated recent request tables).'
        );
    }

    public function testDashboardTemplateMergesAuditFiltersAndColumnsIntoRecentRequests(): void
    {
        $source = $this->dashboardTemplateSource();

        $recentPos = strpos($source, 'COM_MCPSERVER_DASHBOARD_RECENT');
        $this->assertIsInt($recentPos, 'Dashboard template must render the Recent Requests section.');

        $canViewAuditPos = strpos($source, 'canViewAudit', $recentPos);
        $this->assertIsInt(
            $canViewAuditPos,
            'The audit-capable branch must live inside/after the Recent Requests section, not a separate card.'
        );

        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_USER', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_USER_NAME', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_TARGET', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_COL_SELECTOR', $source);
    }

    /**
     * The raw User ID column was replaced by the name. The id must not come
     * back as a column of its own — but it must still reach the operator when
     * it is the only identifier left, which the name cell handles.
     */
    public function testDashboardTemplateShowsUserNameInsteadOfARawUserIdColumn(): void
    {
        $source = $this->dashboardTemplateSource();

        $this->assertDoesNotMatchRegularExpression(
            "/COM_MCPSERVER_GOVERNANCE_AUDIT_COL_USER'/",
            $source,
            'The standalone User ID column must be gone; the name column replaces it.'
        );

        // An account deleted from Joomla leaves a null name. Falling back to
        // "Unavailable" would discard the only identifier the audit row has.
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_USER_DELETED', $source);
        // A null user_id is a different thing again: nobody to attribute to.
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_USER_UNATTRIBUTED', $source);
    }

    public function testDashboardTemplateRendersTheUserFilterAsADropdownWithANumericFallback(): void
    {
        $source = $this->dashboardTemplateSource();

        $this->assertStringContainsString(
            'name="audit_user_id"',
            $source,
            'The User filter must keep its request parameter so existing filter URLs still work.'
        );
        $this->assertStringContainsString('<select class="form-select" id="audit_user_id"', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_FILTER_USER_ANY', $source);

        $this->assertMatchesRegularExpression(
            '/\$this->auditUsers === \[\]\) :.*?<input type="number".*?name="audit_user_id"/s',
            $source,
            'An empty user list must fall back to the numeric box.'
        );
    }

    public function testDashboardViewSkipsTheUserLookupForScopedViewers(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertStringContainsString('getAttributedUsers', $source);
        $this->assertMatchesRegularExpression(
            '/function loadAuditUsers\(\?int \$appliedUserId\): void\s*\{\s*if \(\$this->scopeUserId !== null\) \{\s*return;/s',
            $source,
            'A scoped viewer is not offered the User filter, so the lookup must not run for them.'
        );
    }

    public function testDashboardTemplatePruneControlsAreCoreAdminGatedAndNearRecentRequests(): void
    {
        $source = $this->dashboardTemplateSource();

        $recentPos = strpos($source, 'COM_MCPSERVER_DASHBOARD_RECENT');
        $prunePos = strpos($source, 'credentials.prune');

        $this->assertIsInt($recentPos, 'Dashboard template must render the Recent Requests section.');
        $this->assertIsInt($prunePos, 'Dashboard template must render prune controls.');
        $this->assertGreaterThan($recentPos, $prunePos, 'Prune controls must render after the Recent Requests header, i.e. within that section.');

        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_PRUNE_BUTTON', $source);

        $isCoreAdminPos = strpos($source, 'isCoreAdmin', $recentPos);
        $this->assertIsInt($isCoreAdminPos, 'Prune controls near Recent Requests must be gated by isCoreAdmin.');
        $this->assertLessThan($prunePos, $isCoreAdminPos, 'The core.admin gate must wrap the prune controls.');
    }

    public function testTopWarningBannerIsPreserved(): void
    {
        $source = $this->dashboardTemplateSource();

        $this->assertStringContainsString('COM_MCPSERVER_DASHBOARD_METRICS_DISABLED', $source);

        $warningPos = strpos($source, 'COM_MCPSERVER_DASHBOARD_METRICS_DISABLED');
        $recentPos = strpos($source, 'COM_MCPSERVER_DASHBOARD_RECENT');

        $this->assertLessThan($recentPos, $warningPos, 'The top metrics-disabled warning must render before Recent Requests.');
    }

    public function testSetupControllerActionRedirectsToCredentials(): void
    {
        $source = $this->controllerSource();

        $setupMethod = $this->extractMethodBody($source, 'setup');

        $this->assertStringContainsString('view=credentials', $setupMethod);
        $this->assertStringNotContainsString('view=dashboard', $setupMethod);
    }

    public function testPruneControllerActionRedirectsToDashboard(): void
    {
        $source = $this->controllerSource();

        $pruneMethod = $this->extractMethodBody($source, 'prune');

        $this->assertStringContainsString('view=dashboard', $pruneMethod);
        $this->assertStringNotContainsString('view=credentials', $pruneMethod);
    }

    public function testRequestAndRevokeControllerActionsRedirectToCredentials(): void
    {
        $source = $this->controllerSource();

        $requestMethod = $this->extractMethodBody($source, 'requestAccess');
        $revokeMethod = $this->extractMethodBody($source, 'revoke');

        $this->assertStringContainsString('view=credentials', $requestMethod);
        $this->assertStringContainsString('view=credentials', $revokeMethod);
    }

    public function testSetupRequiresCoreAdmin(): void
    {
        $source = $this->controllerSource();

        $this->assertMatchesRegularExpression(
            "/isAuthorisedForSetupAndTokenValid.*?core\\.admin/s",
            $source,
            'setup() gate must require core.admin.'
        );
    }

    public function testPruneRequiresCoreAdmin(): void
    {
        $source = $this->controllerSource();

        $this->assertMatchesRegularExpression(
            "/isAuthorisedForPruneAndTokenValid.*?core\\.admin/s",
            $source,
            'prune() gate must require core.admin.'
        );
    }

    /**
     * Extracts a public method's body by brace-matching from its declaration,
     * used to scope assertions to one controller action at a time.
     */
    private function extractMethodBody(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:\s*\w+\s*\{/';
        $this->assertMatchesRegularExpression($pattern, $source, "Method {$methodName}() must exist.");

        preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
        $start = $matches[0][1] + strlen($matches[0][0]);

        $depth = 1;
        $pos = $start;
        $length = strlen($source);

        while ($depth > 0 && $pos < $length) {
            $char = $source[$pos];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            $pos++;
        }

        return substr($source, $start, $pos - $start - 1);
    }
}
