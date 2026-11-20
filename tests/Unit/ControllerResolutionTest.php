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
 * Joomla boots a component from administrator/.../services/provider.php in
 * both applications, so front-end requests go through the dispatcher that
 * provider builds. It is the Administrator dispatcher, which resolves every
 * task to Administrator\Controller\<Name>Controller. A controller that exists
 * only under site/src/Controller is never reached and the task fails with
 * "Invalid controller class" (as oauth.* did in 1.12.0).
 */
class ControllerResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testProviderBuildsTheAdministratorDispatcherForEveryApplication(): void
    {
        $provider = (string) file_get_contents(self::ROOT . '/admin/services/provider.php');

        self::assertStringContainsString(
            'use Joomla\Component\Mcpserver\Administrator\Dispatcher\Dispatcher;',
            $provider
        );

        $dispatcher = (string) file_get_contents(self::ROOT . '/admin/src/Dispatcher/Dispatcher.php');
        self::assertStringContainsString("getController(\$name, 'Administrator'", $dispatcher);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function frontEndTasks(): array
    {
        return [
            'rpc.* (MCP endpoint)' => ['Rpc', 'RpcHandlerTrait'],
            'oauth.* (OAuth sign-in)' => ['Oauth', 'OAuthHandlerTrait'],
        ];
    }

    /**
     * @dataProvider frontEndTasks
     */
    public function testFrontEndTaskControllersLiveInTheAdministratorNamespace(string $name, string $trait): void
    {
        $file = self::ROOT . '/admin/src/Controller/' . $name . 'Controller.php';
        self::assertFileExists($file);

        $source = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Joomla\Component\Mcpserver\Administrator\Controller;', $source);
        self::assertStringContainsString('class ' . $name . 'Controller extends BaseController', $source);
        self::assertStringContainsString('use ' . $trait . ';', $source);
    }

    /**
     * The OAuth consent page is rendered from a front-end request too, so its
     * view class must load from the Administrator namespace ("View not found
     * [name, type, prefix]: Authorize, html, Site" otherwise), while its
     * templates stay in the site component folder the controller's basePath
     * points at.
     */
    public function testOAuthConsentViewLoadsFromTheAdministratorNamespace(): void
    {
        $view = self::ROOT . '/admin/src/View/Authorize/HtmlView.php';
        self::assertFileExists($view);
        self::assertStringContainsString(
            'namespace Joomla\Component\Mcpserver\Administrator\View\Authorize;',
            (string) file_get_contents($view)
        );

        $trait = (string) file_get_contents(self::ROOT . '/admin/src/Controller/OAuthHandlerTrait.php');
        self::assertStringContainsString(
            "getView('Authorize', 'html', 'Administrator', ['base_path' => JPATH_SITE . '/components/com_mcpserver'])",
            $trait
        );

        self::assertFileExists(self::ROOT . '/site/tmpl/authorize/default.php');
        self::assertFileExists(self::ROOT . '/site/tmpl/authorize/error.php');
    }

    /**
     * No code a front-end request runs may resolve MVC classes in the Site
     * namespace.
     */
    public function testAdministratorCodeNeverResolvesSiteNamespaceClasses(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/admin/src'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                "/get(View|Model|Controller)\([^)]*'Site'/",
                $source,
                $file->getPathname() . ' resolves an MVC class in the Site namespace'
            );
        }
    }
}
