<?php

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
 * fingerprint), rendered before credential issuance; setup() redirects back
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

    public function testDashboardViewGatesPruneWithCoreAdmin(): void
    {
        $source = $this->dashboardViewSource();

        $this->assertMatchesRegularExpression(
            "/isCoreAdmin\\s*=.*authorise\\('core\\.admin', 'com_mcpserver'\\)/s",
            $source,
            'Dashboard must gate prune visibility behind core.admin.'
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
            "/isCoreAdmin\\s*=.*authorise\\('core\\.admin', 'com_mcpserver'\\)/s",
            $source,
            'Credentials view must gate governance setup status behind core.admin.'
        );
    }

    public function testCredentialsTemplateSetupCardRendersBeforeIssueCredentialCard(): void
    {
        $source = $this->credentialsTemplateSource();

        $setupPos = strpos($source, 'COM_MCPSERVER_GOVERNANCE_SETUP_TITLE');
        $createPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_CREATE_TITLE');

        $this->assertIsInt($setupPos, 'Credentials template must render the governance setup section.');
        $this->assertIsInt($createPos, 'Credentials template must render the issue-credential section.');

        $this->assertLessThan($createPos, $setupPos, 'Governed Mode Setup must render before Issue New Credential.');
    }

    public function testCredentialsTemplateSetupFormPostsToCredentialsSetupTask(): void
    {
        $source = $this->credentialsTemplateSource();

        $this->assertStringContainsString('task=credentials.setup', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_SETUP_BUTTON', $source);
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

    public function testCredentialsTemplateOrdersWarningThenSetupThenIssuedTokenThenCreateThenList(): void
    {
        $source = $this->credentialsTemplateSource();

        $warningPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_NOT_CONFIGURED');
        $setupPos = strpos($source, 'COM_MCPSERVER_GOVERNANCE_SETUP_TITLE');
        $issuedPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_ISSUED_TITLE');
        $createPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_CREATE_TITLE');
        $listPos = strpos($source, 'COM_MCPSERVER_CREDENTIALS_LIST_TITLE');

        $this->assertIsInt($warningPos);
        $this->assertIsInt($setupPos);
        $this->assertIsInt($issuedPos);
        $this->assertIsInt($createPos);
        $this->assertIsInt($listPos);

        $this->assertLessThan($setupPos, $warningPos, 'Warning must render first.');
        $this->assertLessThan($issuedPos, $setupPos, 'Governed Mode Setup must render before the one-time issued token.');
        $this->assertLessThan($createPos, $issuedPos, 'The one-time issued token must render before the create form.');
        $this->assertLessThan($listPos, $createPos, 'Issue New Credential must render before the credential list.');
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
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_USER', $source);
        $this->assertStringContainsString('COM_MCPSERVER_GOVERNANCE_AUDIT_COL_TARGET', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_COL_SELECTOR', $source);
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

    public function testCreateAndRevokeControllerActionsRedirectToCredentials(): void
    {
        $source = $this->controllerSource();

        $createMethod = $this->extractMethodBody($source, 'create');
        $revokeMethod = $this->extractMethodBody($source, 'revoke');

        $this->assertStringContainsString('view=credentials', $createMethod);
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
