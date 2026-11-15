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
 * The release is pkg_mcpserver: the component plus the system plugin. These
 * pin what the build and the update feed rely on.
 */
final class PackageManifestTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function manifest(string $path): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ROOT . '/' . $path);
        self::assertNotFalse($xml, $path . ' must be well-formed');

        return $xml;
    }

    public function testPackageInstallsTheComponentAndTheSystemPlugin(): void
    {
        $package = self::manifest('pkg_mcpserver.xml');

        $this->assertSame('package', (string) $package['type']);
        $this->assertSame('mcpserver', (string) $package->packagename);
        $files = [];
        foreach ($package->files->file as $file) {
            $files[(string) $file['type'] . ':' . (string) $file['id']] = (string) $file;
        }
        $this->assertSame(
            ['component:com_mcpserver' => 'com_mcpserver.zip', 'plugin:mcpserver' => 'plg_system_mcpserver.zip'],
            $files
        );
        $this->assertSame('system', (string) $package->files->file[1]['group']);
        $this->assertFileExists(self::ROOT . '/' . (string) $package->scriptfile);
    }

    public function testPackageAndPluginCarryTheComponentVersion(): void
    {
        $version = (string) self::manifest('mcpserver.xml')->version;

        $this->assertSame($version, (string) self::manifest('pkg_mcpserver.xml')->version);
        $this->assertSame($version, (string) self::manifest('plugins/system/mcpserver/mcpserver.xml')->version);
    }

    public function testOnlyThePackageHasAnUpdateServer(): void
    {
        // A child with its own update server would be offered every update twice.
        $this->assertCount(0, self::manifest('mcpserver.xml')->updateservers);
        $this->assertCount(1, self::manifest('pkg_mcpserver.xml')->updateservers->server);
    }

    public function testComponentUpdateEntryExistsOnlyForTheMigrationRelease(): void
    {
        $feed = (string) file_get_contents(self::ROOT . '/scripts/sync_update_entry.py');

        $this->assertMatchesRegularExpression('/^MIGRATION_VERSION = "1\.12\.0"$/m', $feed);
        $this->assertStringContainsString('pkg_mcpserver-{version}.zip', $feed);
        $this->assertStringNotContainsString('com_mcpserver-{version}.zip', $feed);
    }
}
