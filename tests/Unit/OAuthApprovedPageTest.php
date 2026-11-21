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
 * The page shown after the user approves an OAuth client: it confirms the
 * approval in the site's template, then hands the browser on to the client.
 */
final class OAuthApprovedPageTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../site/tmpl/authorize/approved.php';

    /**
     * @param array<string,mixed> $oauth
     */
    private function render(array $oauth): string
    {
        $view = new class () {
            /** @var array<string,mixed> */
            public array $oauth = [];
        };
        $view->oauth = $oauth;

        $template = self::TEMPLATE;

        return (function () use ($template): string {
            ob_start();
            include $template;

            return (string) ob_get_clean();
        })->call($view);
    }

    /**
     * @return array<string,mixed>
     */
    private function approval(string $redirectUrl): array
    {
        return [
            'clientName' => 'Example CLI',
            'siteName' => 'Inner Space',
            'userName' => 'Jo Bloggs',
            'redirectUrl' => $redirectUrl,
        ];
    }

    public function testNamesTheClientTheSiteAndTheUser(): void
    {
        $html = $this->render($this->approval('http://127.0.0.1:33418/callback?code=abc&state=xyz'));

        $this->assertStringContainsString('Example CLI', $html);
        $this->assertStringContainsString('Inner Space', $html);
        $this->assertStringContainsString('Jo Bloggs', $html);
    }

    public function testHandsTheBrowserOnToTheExactRedirectUrlWithoutJavaScript(): void
    {
        $html = $this->render($this->approval('http://127.0.0.1:33418/callback?code=abc&state=xyz'));

        $this->assertStringContainsString(
            '<meta http-equiv="refresh" content="2;url=http://127.0.0.1:33418/callback?code=abc&amp;state=xyz">',
            $html
        );
        $this->assertStringContainsString('href="http://127.0.0.1:33418/callback?code=abc&amp;state=xyz"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testHandsOverToAPrivateUseSchemeUriUnchanged(): void
    {
        $html = $this->render($this->approval('com.example.app:/cb?code=abc'));

        $this->assertStringContainsString('content="2;url=com.example.app:/cb?code=abc"', $html);
        $this->assertStringContainsString('href="com.example.app:/cb?code=abc"', $html);
    }

    public function testACraftedRedirectUrlCannotBreakOutOfTheMarkup(): void
    {
        $html = $this->render($this->approval('https://client.example/cb?code=abc&state="><script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('state=&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testACraftedClientNameIsEscaped(): void
    {
        $oauth = $this->approval('http://127.0.0.1:33418/callback?code=abc');
        $oauth['clientName'] = '<img src=x onerror=alert(1)>';

        $html = $this->render($oauth);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testSaysTheClientNameIsUnverified(): void
    {
        $html = $this->render($this->approval('http://127.0.0.1:33418/callback?code=abc'));

        $this->assertStringContainsString('COM_MCPSERVER_OAUTH_APPROVED_UNVERIFIED_NAME: Example CLI', $html);
    }

    /**
     * Source-level, as in CredentialRequestUiWorkflowTest: the controller
     * needs a Joomla application, which the unit harness does not have.
     */
    private function controllerSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../admin/src/Controller/OauthController.php');
    }

    private function methodSource(string $name): string
    {
        $source = $this->controllerSource();
        $start = strpos($source, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . '() not found');
        $end = strpos($source, "\n    }\n", $start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    public function testApprovalRendersTheConfirmationPageInsteadOfRedirecting(): void
    {
        $decide = $this->methodSource('decideAuthorization');

        $this->assertStringContainsString('$this->renderApproval($request, (string) $user->name, $url)', $decide);
        $this->assertStringNotContainsString('$this->redirectToClient($url)', $decide);
    }

    public function testDenyAndRedirectableErrorsStillGoStraightToTheClient(): void
    {
        $this->assertStringContainsString('$this->redirectToClient($server->denyRedirect($request))', $this->methodSource('decideAuthorization'));
        $this->assertStringContainsString('$this->redirectToClient($server->errorRedirect($e))', $this->methodSource('authorize'));
    }

    public function testConfirmationPageRendersTheApprovedLayoutWithoutLeakingTheCode(): void
    {
        $method = $this->methodSource('renderApproval');

        $this->assertStringContainsString("renderOAuthPage('approved'", $method);
        $this->assertStringContainsString("'redirectUrl' => \$redirectUrl", $method);
        $this->assertStringContainsString("setHeader('Referrer-Policy', 'no-referrer', true)", $method);
        // authorize() sends Cache-Control: no-store before handing a POST to decideAuthorization().
        $this->assertStringContainsString("\$app->setHeader('Cache-Control', 'no-store', true);", $this->methodSource('authorize'));
    }
}
