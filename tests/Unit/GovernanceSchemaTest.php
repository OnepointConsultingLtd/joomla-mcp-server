<?php

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
}
