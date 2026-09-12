<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression coverage for the request/approval/claim UI wiring.
 * A Joomla MVC application is not available to the unit harness, so this
 * verifies the observable action, DI, and section ownership contracts.
 */
final class CredentialRequestUiWorkflowTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function source(string $path): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $path);
    }

    public function testProviderRegistersTheRequestWorkflowServices(): void
    {
        $source = $this->source('admin/services/provider.php');

        $this->assertStringContainsString('JoomlaCredentialRequestStore::class', $source);
        $this->assertStringContainsString('JoomlaApiTokenOwnershipValidator::class', $source);
        $this->assertStringContainsString('CredentialRequestService::class', $source);
    }

    public function testControllerUsesRequestWorkflowActionsInsteadOfDirectIssuance(): void
    {
        $source = $this->source('admin/src/Controller/CredentialsController.php');

        foreach (['requestAccess', 'approve', 'reject', 'claim'] as $action) {
            $this->assertMatchesRegularExpression('/function\s+' . $action . '\s*\(/', $source);
        }

        $this->assertStringNotContainsString('function create(', $source);
        $this->assertStringContainsString('getCredentialRequestService()', $source);
        $this->assertStringContainsString("authorise('core.admin')", $source);
        $this->assertStringContainsString('isAuthorisedForSuperUserAndTokenValid', $source);
        $this->assertStringContainsString("authorise('mcpserver.credential.self', 'com_mcpserver')", $source);
    }

    public function testCredentialsViewLoadsOnlyOwnRequestsAndAdminPendingQueue(): void
    {
        $source = $this->source('admin/src/View/Credentials/HtmlView.php');

        $this->assertStringContainsString('listForUser((int) $user->id)', $source);
        $this->assertStringContainsString('listPending()', $source);
        $this->assertStringContainsString('if ($this->isCoreAdmin)', $source);
        $this->assertStringContainsString('listAllMetadata(true)', $source);
    }

    public function testTemplateProvidesRequestClaimAndIndependentAdminDecisionFormsInOrder(): void
    {
        $source = $this->source('admin/tmpl/credentials/default.php');

        $this->assertStringNotContainsString('task=credentials.create', $source);
        foreach (['task=credentials.requestAccess', 'task=credentials.claim', 'task=credentials.approve', 'task=credentials.reject'] as $task) {
            $this->assertStringContainsString($task, $source);
        }
        $this->assertSame(1, substr_count($source, 'name="api_token"'), 'The Joomla API token may be submitted only by the approved-request claim form.');
        $this->assertStringContainsString('name="expires_days"', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_REQUEST_TITLE', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_REQUESTS_TITLE', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_PENDING_TITLE', $source);
        $this->assertStringContainsString('COM_MCPSERVER_CREDENTIALS_ADMIN_LIST_TITLE', $source);
        $this->assertMatchesRegularExpression(
            '/adminCredentials.*?COM_MCPSERVER_CREDENTIALS_COL_EXPIRES.*?expires_at/s',
            $source
        );

        $this->assertLessThan(
            strpos($source, 'COM_MCPSERVER_CREDENTIALS_PENDING_TITLE'),
            strpos($source, 'COM_MCPSERVER_CREDENTIALS_REQUESTS_TITLE'),
            'A user must see their own requests before the Super User queue.'
        );
    }
}
