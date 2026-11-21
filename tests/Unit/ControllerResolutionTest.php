<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Component\Mcpserver\Administrator\Service\ComponentTaskUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every task URL the component hands out must reach code that runs.
 *
 * Joomla boots a component only from administrator/.../services/provider.php,
 * in the site application too, and that provider builds the Administrator
 * dispatcher. It turns `task=<name>.<action>` into
 * Administrator\Controller\<Name>Controller and calls <action> on it. A task
 * whose controller is anywhere else, or whose method is missing or not
 * public, fails at run time ("Invalid controller class", as oauth.* did in
 * 1.12.0), and no other unit test notices.
 *
 * The tasks are collected from the URLs the component advertises (plugin
 * redirects, OAuth metadata URLs, the consent form's action, the RPC, SSE and
 * health URLs in the code and README), so a newly advertised task is checked
 * without editing this test.
 */
final class ControllerResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CONTROLLER_NAMESPACE = 'Joomla\\Component\\Mcpserver\\Administrator\\Controller\\';

    /** Where the component's URLs are written down. */
    private const SOURCES = ['admin/src', 'admin/tmpl', 'plugins', 'site', 'README.md'];

    /**
     * Front-end tasks MCP clients, the system plugin and the consent page
     * depend on. If the scan stops finding one, the scan is broken.
     */
    private const FRONT_END_TASKS = ['rpc.handle', 'rpc.sse', 'rpc.upload', 'health.ping', 'oauth.authorize', 'oauth.resource'];

    /**
     * @return array<string,string> Task => a file that advertises it.
     */
    private static function advertisedTasks(): array
    {
        $patterns = [
            // index.php?option=com_mcpserver&task=rpc.handle (also &amp; in templates)
            '/option=com_mcpserver&(?:amp;)?task=([A-Za-z]+\.[A-Za-z]+)/',
            // OAuthConfig::componentUrl('oauth.resource')
            "/componentUrl\\('([A-Za-z]+\\.[A-Za-z]+)'\\)/",
        ];

        $tasks = [];
        foreach (self::SOURCES as $source) {
            foreach (self::files(self::ROOT . '/' . $source) as $file) {
                $content = (string) file_get_contents($file);
                foreach ($patterns as $pattern) {
                    preg_match_all($pattern, $content, $matches);
                    foreach ($matches[1] as $task) {
                        $tasks[$task] ??= substr($file, strlen(self::ROOT) + 1);
                    }
                }
            }
        }
        // The MCP endpoint's task is built from a constant rather than written
        // out, so the scan above cannot see where the code advertises it.
        $tasks[ComponentTaskUrl::MCP_ENDPOINT] ??= 'admin/src/Service/ComponentTaskUrl.php';
        ksort($tasks);

        return $tasks;
    }

    /**
     * @return list<string>
     */
    private static function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (in_array($file->getExtension(), ['php', 'js', 'md'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function tasks(): array
    {
        $cases = [];
        foreach (self::advertisedTasks() as $task => $file) {
            $cases[$task] = [$task, $file];
        }

        return $cases;
    }

    public function testTheScanFindsEveryFrontEndTask(): void
    {
        $missing = array_diff(self::FRONT_END_TASKS, array_keys(self::advertisedTasks()));

        $this->assertSame([], array_values($missing), 'These front-end tasks are no longer found in any advertised URL');
    }

    /**
     * Resolves the task the way the Administrator dispatcher and
     * BaseController do: the controller's public methods are its tasks,
     * except those BaseController itself declares, and display() is always one.
     */
    #[DataProvider('tasks')]
    public function testAdvertisedTaskReachesAPublicAdministratorControllerMethod(string $task, string $advertisedIn): void
    {
        [$name, $action] = explode('.', $task, 2);
        $class = self::CONTROLLER_NAMESPACE . ucfirst(strtolower($name)) . 'Controller';

        $this->assertTrue(class_exists($class), "task={$task} (advertised in {$advertisedIn}) needs {$class}");
        $this->assertTrue(is_subclass_of($class, BaseController::class), "{$class} must be a Joomla controller");

        $controller = new \ReflectionClass($class);
        $this->assertTrue($controller->hasMethod($action), "task={$task} (advertised in {$advertisedIn}) needs {$class}::{$action}()");

        $method = $controller->getMethod($action);
        $this->assertTrue($method->isPublic(), "{$class}::{$action}() must be public to be callable as a task");

        $baseMethods = array_map('strtolower', get_class_methods(BaseController::class));
        $this->assertTrue(
            strtolower($action) === 'display' || !in_array(strtolower($action), $baseMethods, true),
            "task={$task}: {$action}() is one of BaseController's own methods, which Joomla never runs as a task"
        );
    }

    /**
     * The component has no Site-namespace classes, so a view, model or
     * controller resolved with the 'Site' prefix fails at run time ("View not
     * found [name, type, prefix]: Authorize, html, Site", as the OAuth consent
     * page did in 1.12.0). Front-end views load from the Administrator
     * namespace with base_path pointing at the site templates.
     */
    public function testNoCodeResolvesMvcClassesInTheSiteNamespace(): void
    {
        foreach (self::files(self::ROOT . '/admin/src') as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/get(View|Model|Controller)\\([^)]*'Site'/",
                (string) file_get_contents($file),
                $file . ' resolves an MVC class in the Site namespace'
            );
        }
    }
}
