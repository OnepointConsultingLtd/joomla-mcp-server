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

final class OAuthSchemaTest extends TestCase
{
    private const INSTALL_SQL = __DIR__ . '/../../admin/sql/install.mysql.utf8.sql';
    private const UPDATE_SQL = __DIR__ . '/../../admin/sql/updates/mysql/1.12.0.sql';

    private const TABLES = ['oauth_client', 'oauth_grant', 'oauth_code', 'oauth_token'];

    public function testOAuthTablesAreCreatedOnInstallAndUpgrade(): void
    {
        foreach ([self::INSTALL_SQL, self::UPDATE_SQL] as $file) {
            $sql = (string) file_get_contents($file);
            foreach (self::TABLES as $table) {
                $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__mcpserver_' . $table . '`', $sql, basename($file));
            }
        }
    }

    public function testCodesAndTokensAreStoredOnlyAsUniqueHashes(): void
    {
        $sql = (string) file_get_contents(self::INSTALL_SQL);

        $this->assertStringContainsString('UNIQUE KEY `idx_code_hash` (`code_hash`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `idx_token_hash` (`token_hash`)', $sql);
        $this->assertStringContainsString('`secret_hash`', $sql);
        foreach (['`access_token`', '`refresh_token`', '`client_secret`', '`code` '] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $sql);
        }
    }

    public function testUpgradeIsAdditive(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE|MODIFY|CHANGE|ALTER)\b/i', (string) file_get_contents(self::UPDATE_SQL));
    }
}
