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
        // A claimed request is already shown as a credential; listing it twice
        // left a row that duplicated the credential and could not be acted on.
        $this->assertStringContainsString("\$request['status'] !== 'claimed'", $source);
        $this->assertStringContainsString('if ($this->isCoreAdmin)', $source);
        $this->assertStringContainsString('listAllMetadata(true)', $source);
    }

    public function testAccountEditLinkTargetsComUsersSelfEdit(): void
    {
        $source = $this->source('admin/src/View/Credentials/HtmlView.php');

        // com_admin's profile view is gone as of Joomla 5 and 404s; com_users
        // self-edit is the route core itself links, and is reachable without
        // com_users permissions when the id is the acting user's own.
        $this->assertStringNotContainsString('option=com_admin', $source);
        $this->assertStringContainsString('index.php?option=com_users&task=user.edit&id=', $source);
        $this->assertStringContainsString('#attrib-joomlatoken', $source);
    }

    public function testOwnCredentialListIdentifiesRowsByClientNameNotOwnerName(): void
    {
        $source = $this->source('admin/tmpl/credentials/default.php');

        // Every row in "Your credentials" belongs to the viewing user, so
        // owner_name was the same string on all of them; client_name is what
        // distinguishes one credential from another. The Super User list keeps
        // owner_name, where it does identify whose credential a row is.
        $this->assertStringContainsString("credential['client_name']", $source);
        $this->assertSame(1, substr_count($source, "\$credential['owner_name']"));
    }

    public function testClaimFieldSuppressesPasswordManagerAutofill(): void
    {
        $source = $this->source('admin/tmpl/credentials/default.php');

        // Browsers ignore autocomplete="off" on password inputs, so the saved
        // admin login gets offered as the Joomla API token without this.
        $this->assertMatchesRegularExpression(
            '/name="api_token"[^>]*autocomplete="new-password"/',
            $source
        );
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
