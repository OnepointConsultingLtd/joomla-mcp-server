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

    /**
     * Joomla keeps one update per feed, whatever its <element>: the highest
     * version, and of equal versions the first one read. If the package entry
     * for the migration release came first, a site with just the component
     * installed would never be offered it.
     */
    public function testComponentMigrationEntryPrecedesThePackageEntry(): void
    {
        $entries = [];
        foreach (self::manifest('update.xml')->update as $update) {
            $entries[] = (string) $update->element . '@' . (string) $update->version;
        }

        $component = array_search('com_mcpserver@1.12.0', $entries, true);
        $package = array_search('pkg_mcpserver@1.12.0', $entries, true);

        $this->assertNotFalse($component);
        $this->assertNotFalse($package);
        $this->assertLessThan($package, $component);
    }

    /**
     * Joomla's installer copies a component's front-end files only from a
     * top-level <files> element. A <files> nested in <site> is ignored, which
     * left components/com_mcpserver/ uninstalled until 1.12.0 (the OAuth
     * consent page then failed with "Layout error not found").
     */
    public function testComponentFrontEndFilesAreATopLevelFilesElement(): void
    {
        $component = self::manifest('mcpserver.xml');

        $this->assertCount(0, $component->xpath('/extension/site'), 'Joomla ignores a <site> element; use a top-level <files folder="site">');
        $this->assertCount(1, $component->xpath('/extension/files'));
        $this->assertSame('site', (string) $component->files['folder']);

        $folders = array_map('strval', iterator_to_array($component->files->folder, false));
        $filenames = array_map('strval', iterator_to_array($component->files->filename, false));
        foreach (['tmpl', 'language'] as $folder) {
            $this->assertContains($folder, $folders);
            $this->assertDirectoryExists(self::ROOT . '/site/' . $folder);
        }
        foreach (['icon.png', 'mcp-http-bridge.js'] as $filename) {
            $this->assertContains($filename, $filenames);
            $this->assertFileExists(self::ROOT . '/site/' . $filename);
        }
    }

    /**
     * Joomla boots the component from the administrator provider and routes
     * every front-end task to an Administrator controller, so site-side
     * routing (provider, dispatcher, controllers, the legacy entry file and
     * router) is never run. Shipping it misled contributors into adding a
     * front-end task there, which is how oauth.* was unreachable in 1.12.0.
     */
    public function testComponentShipsNoSiteSideRouting(): void
    {
        $component = self::manifest('mcpserver.xml');
        $folders = array_map('strval', iterator_to_array($component->files->folder, false));
        $filenames = array_map('strval', iterator_to_array($component->files->filename, false));

        foreach (['src', 'services'] as $folder) {
            $this->assertNotContains($folder, $folders);
            $this->assertDirectoryDoesNotExist(self::ROOT . '/site/' . $folder);
        }
        foreach (['mcpserver.php', 'router.php'] as $filename) {
            $this->assertNotContains($filename, $filenames);
            $this->assertFileDoesNotExist(self::ROOT . '/site/' . $filename);
        }
    }
}
