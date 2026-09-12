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

class GovernanceSchemaTest extends TestCase
{
    private const INSTALL_SQL = __DIR__ . '/../../admin/sql/install.mysql.utf8.sql';
    private const UPDATE_SQL = __DIR__ . '/../../admin/sql/updates/mysql/1.8.0.sql';

    public function testCredentialStorageContainsOnlyEncryptedJoomlaApiTokenMaterial(): void
    {
        $sql = (string) file_get_contents(self::INSTALL_SQL);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__mcpserver_credential`', $sql);
        foreach (['selector', 'user_id', 'verifier', 'token_ciphertext', 'token_nonce', 'token_tag', 'key_version', 'expires', 'revoked'] as $column) {
            $this->assertStringContainsString('`' . $column . '`', $sql);
        }
        $this->assertStringNotContainsString('`api_token`', $sql);
        $this->assertStringNotContainsString('`mcp_bearer_token`', $sql);
        $this->assertStringContainsString('UNIQUE KEY `idx_selector`', $sql);
    }

    public function testRequestLogStoresAttributionWithoutBreakingExistingRows(): void
    {
        $sql = (string) file_get_contents(self::INSTALL_SQL);

        foreach (['request_id', 'credential_id', 'user_id', 'credential_selector', 'target'] as $column) {
            $this->assertMatchesRegularExpression('/`' . $column . '`[^,]*NULL(?! NOT)/i', $sql);
        }
    }

    public function testUpgradeScriptIsAdditiveAndAddsGovernanceSchema(): void
    {
        $sql = (string) file_get_contents(self::UPDATE_SQL);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__mcpserver_credential`', $sql);
        $this->assertStringContainsString('ALTER TABLE `#__mcpserver_request_log`', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE|MODIFY|CHANGE)\b/i', $sql);
    }

    public function testCredentialRequestsAndImmutableEventsAreInstalledAndUpgradeable(): void
    {
        $install = (string) file_get_contents(self::INSTALL_SQL);
        $update = (string) file_get_contents(self::UPDATE_SQL);

        foreach ([$install, $update] as $sql) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__mcpserver_credential_request`', $sql);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__mcpserver_request_event`', $sql);
            foreach (['user_id', 'client_name', 'status', 'requested', 'decided', 'decided_by', 'credential_expires', 'claimed', 'credential_id'] as $column) {
                $this->assertStringContainsString('`' . $column . '`', $sql);
            }
        }

        $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE|MODIFY|CHANGE)\b/i', $update);
    }

    /**
     * The upgrade script must be named for the version in mcpserver.xml, and no
     * update file may claim a version newer than it.
     *
     * Joomla applies update SQL by filename, so a file named for a version the
     * manifest never reaches is dead code that silently never runs — and the
     * schema it carries is simply missing on every upgrading site.
     */
    public function testUpdateScriptsMatchTheManifestVersion(): void
    {
        $manifest = simplexml_load_file(__DIR__ . '/../../mcpserver.xml');
        $version = (string) $manifest->version;

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->assertFileExists(
            __DIR__ . '/../../admin/sql/updates/mysql/' . $version . '.sql',
            'the manifest version must have a matching update script'
        );

        $files = glob(__DIR__ . '/../../admin/sql/updates/mysql/*.sql') ?: [];
        foreach ($files as $file) {
            $fileVersion = basename($file, '.sql');
            $this->assertLessThanOrEqual(
                0,
                version_compare($fileVersion, $version),
                "update script {$fileVersion}.sql is newer than the manifest version {$version} and would never run"
            );
        }
    }

    /**
     * Every ALTER in the upgrade must carry Joomla's "CAN FAIL" marker.
     *
     * MySQL cannot express ADD COLUMN IF NOT EXISTS. If a statement later in
     * the file fails transiently, Joomla returns without recording the schema
     * version (Installer::parseSchemaUpdates), so the file is retried — and an
     * unmarked ALTER then aborts the whole upgrade on "Duplicate column name",
     * permanently. The marker is what makes the retry survivable.
     */
    public function testUpgradeAltersAreIndividuallyRetryable(): void
    {
        $marker = '/*' . '* CAN FAIL *' . '*/';

        // Strip -- comment lines first: they sit above the statement they
        // describe and would otherwise be counted as part of it.
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(self::UPDATE_SQL)) ?? '';
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $alters = array_filter(
            $statements,
            static fn (string $s): bool => stripos($s, 'ALTER TABLE') !== false
        );

        $this->assertNotEmpty($alters, 'the upgrade is expected to alter the request log');

        foreach ($alters as $statement) {
            $this->assertStringEndsWith(
                $marker,
                $statement,
                'ALTER statements must be marked CAN FAIL so a retried upgrade does not abort: '
                . substr($statement, 0, 80)
            );

            // One clause per statement, so an already-applied column cannot
            // prevent the remaining ones from being applied.
            $this->assertSame(
                1,
                preg_match_all('/\bADD (?:COLUMN|KEY)\b/i', $statement),
                'each ALTER must carry exactly one ADD clause: ' . substr($statement, 0, 80)
            );
        }
    }
}
