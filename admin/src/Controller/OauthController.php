<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mcpserver\Administrator\Extension\McpserverComponent;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestOAuthClaims;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialRequestService;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthAuthorizationRequest;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthEndpoints;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthError;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthHttpResponse;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServer;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServiceFactory;

/**
 * The OAuth authorisation server's oauth.* tasks. Site context only: the
 * authorisation endpoint signs a person in to the site.
 *
 * plg_system_mcpserver serves the same endpoints at the clean URLs advertised
 * in the metadata; these tasks are what it redirects the authorisation
 * endpoint to, and a fallback for the rest.
 */
class OauthController extends BaseController
{
    /** Session key holding authorisation requests awaiting the user's decision. */
    private const PENDING_KEY = 'com_mcpserver.oauth.pending';

    /** How long the consent page stays valid. */
    private const PENDING_LIFETIME = 600;

    public function token(): void
    {
        $this->serveOAuthEndpoint(OAuthEndpoints::TOKEN);
    }

    public function register(): void
    {
        $this->serveOAuthEndpoint(OAuthEndpoints::REGISTER);
    }

    public function revoke(): void
    {
        $this->serveOAuthEndpoint(OAuthEndpoints::REVOKE);
    }

    public function metadata(): void
    {
        $this->serveOAuthEndpoint(OAuthEndpoints::AUTHORIZATION_SERVER);
    }

    public function resource(): void
    {
        $this->serveOAuthEndpoint(OAuthEndpoints::PROTECTED_RESOURCE);
    }

    /**
     * The authorisation endpoint. GET validates the client's request, makes
     * sure the user is signed in, and shows the consent page; the consent
     * form POSTs back here.
     */
    public function authorize(): void
    {
        $app = Factory::getApplication();
        $params = OAuthServiceFactory::params();

        if ($app->getName() !== 'site' || !OAuthConfig::isEnabled($params)) {
            throw new \Exception(Text::_('COM_MCPSERVER_OAUTH_DISABLED'), 404);
        }

        $server = OAuthServiceFactory::server($params, $this->oauthCredentialClaims());

        // No caching and no framing anywhere in the flow (clickjacking on the
        // consent button is the classic attack on this page).
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Frame-Options', 'DENY', true);
        $app->setHeader('Content-Security-Policy', "frame-ancestors 'none'", true);

        if ($app->input->getMethod() === 'POST') {
            $this->decideAuthorization($server);

            return;
        }

        // Sign-in comes first. Validation errors are redirected to the client,
        // and registration is open, so validating for an anonymous visitor
        // would let anyone use this site as an open redirector (RFC 9700 §4.11.2).
        $user = $app->getIdentity();
        if ($user === null || $user->guest) {
            // Joomla's own login, so its MFA and login plugins apply, then back here.
            $app->redirect(Route::_(
                'index.php?option=com_users&view=login&return=' . urlencode(base64_encode(Uri::getInstance()->toString())),
                false
            ));

            return;
        }

        try {
            $request = $server->validateAuthorizationRequest(
                OAuthServiceFactory::rawParams($app->input->get, OAuthServiceFactory::AUTHORIZE_PARAMS)
            );
        } catch (OAuthError $e) {
            if ($e->isRedirectable()) {
                $this->redirectToClient($server->errorRedirect($e));

                return;
            }

            $this->renderOAuthError(Text::sprintf('COM_MCPSERVER_OAUTH_ERROR_INVALID_REQUEST', $e->description));

            return;
        }

        $session = $app->getSession();
        $pending = $this->livePendingRequests((array) $session->get(self::PENDING_KEY, []));
        $pendingId = bin2hex(random_bytes(16));
        // A handful at most: one per tab the user might have open.
        $pending = array_slice($pending, -4, null, true);
        $pending[$pendingId] = ['created' => time(), 'user_id' => (int) $user->id, 'request' => $request->toArray()];
        $session->set(self::PENDING_KEY, $pending);

        $this->renderOAuthPage('default', [
            'request' => $request,
            'credentials' => $server->usableCredentials((int) $user->id),
            'claimable' => $server->claimableRequests((int) $user->id),
            'pendingId' => $pendingId,
            'userName' => (string) $user->name,
            'action' => Uri::base() . 'index.php?option=com_mcpserver&task=oauth.authorize',
        ]);
    }

    private function decideAuthorization(OAuthServer $server): void
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            $this->renderOAuthError(Text::_('COM_MCPSERVER_OAUTH_ERROR_EXPIRED'), 403);

            return;
        }

        $user = $app->getIdentity();
        $session = $app->getSession();
        $pending = $this->livePendingRequests((array) $session->get(self::PENDING_KEY, []));
        $pendingId = $app->input->post->getAlnum('pending', '');
        $entry = $pending[$pendingId] ?? null;

        // Single use, whatever the outcome.
        unset($pending[$pendingId]);
        $session->set(self::PENDING_KEY, $pending);

        if ($user === null || $user->guest || !is_array($entry) || ($entry['user_id'] ?? 0) !== (int) $user->id) {
            $this->renderOAuthError(Text::_('COM_MCPSERVER_OAUTH_ERROR_EXPIRED'), 403);

            return;
        }

        try {
            $request = OAuthAuthorizationRequest::fromArray((array) $entry['request']);
        } catch (\InvalidArgumentException) {
            $this->renderOAuthError(Text::_('COM_MCPSERVER_OAUTH_ERROR_EXPIRED'), 403);

            return;
        }

        if ($app->input->post->getCmd('decision', '') !== 'approve') {
            $this->redirectToClient($server->denyRedirect($request));

            return;
        }

        // One radio group on the consent page: "credential:<id>" binds an
        // existing credential, "request:<id>" claims an approved request.
        [$kind, $id] = array_pad(explode(':', $app->input->post->getString('binding', ''), 2), 2, '');

        try {
            $url = $kind === 'request'
                ? $server->approveByClaiming($request, (int) $user->id, (string) $user->username, $id)
                : $server->approve($request, (int) $user->id, ctype_digit($id) ? (int) $id : 0);
        } catch (OAuthError $e) {
            $this->renderOAuthError($kind === 'request'
                ? Text::sprintf('COM_MCPSERVER_OAUTH_ERROR_CLAIM', $e->description)
                : Text::_('COM_MCPSERVER_OAUTH_ERROR_NO_CREDENTIAL'));

            return;
        } catch (\Throwable $e) {
            if ($kind !== 'request') {
                throw $e;
            }
            // A fault after the claim: the server revokes what it created, or
            // says which credential it could not. Log it rather than show it.
            Log::add('OAuth consent claim failed: ' . $e->getMessage(), Log::ERROR, 'com_mcpserver');
            $this->renderOAuthError(Text::_('COM_MCPSERVER_OAUTH_ERROR_CLAIM_FAILED'), 500);

            return;
        }

        $this->redirectToClient($url);
    }

    /**
     * Lets the consent page claim approved credential requests, through the
     * same services the credentials page uses. Null when those services are
     * unavailable (for example, Governed Mode not yet set up): the page then
     * offers existing credentials only.
     */
    private function oauthCredentialClaims(): ?CredentialRequestOAuthClaims
    {
        try {
            $container = McpserverComponent::getServiceContainer();
            if ($container === null
                || !$container->has(CredentialRequestService::class)
                || !$container->has(CredentialLifecycleService::class)) {
                return null;
            }

            return new CredentialRequestOAuthClaims(
                $container->get(CredentialRequestService::class),
                $container->get(CredentialLifecycleService::class)
            );
        } catch (\Throwable $e) {
            Log::add('OAuth consent cannot offer credential requests: ' . $e->getMessage(), Log::WARNING, 'com_mcpserver');

            return null;
        }
    }

    /**
     * @param array<string,mixed> $pending
     *
     * @return array<string,array<string,mixed>>
     */
    private function livePendingRequests(array $pending): array
    {
        $cutoff = time() - self::PENDING_LIFETIME;

        return array_filter(
            $pending,
            static fn ($entry): bool => is_array($entry) && (int) ($entry['created'] ?? 0) > $cutoff
        );
    }

    /**
     * Sends the browser to the client's redirect URI. Not $app->redirect():
     * that treats a private-use scheme URI such as `com.example.app:/cb` as
     * relative and prefixes the site URL.
     */
    private function redirectToClient(string $url): void
    {
        $app = Factory::getApplication();
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, 302);
        $app->close();
    }

    private function renderOAuthError(string $message, int $status = 400): void
    {
        $this->renderOAuthPage('error', ['message' => $message], $status);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function renderOAuthPage(string $layout, array $data, int $status = 200): void
    {
        $app = Factory::getApplication();
        $app->setHeader('status', (string) $status, true);

        // An Administrator-namespace view, like every class a front-end request
        // loads: the component is booted from the administrator provider, so
        // front-end requests never rely on the Site namespace. Its templates
        // are the site's; the path is explicit rather than the controller's
        // basePath, which defaults to JPATH_COMPONENT (deprecated in Joomla 4.3).
        $view = $this->getView('Authorize', 'html', 'Administrator', ['base_path' => JPATH_SITE . '/components/com_mcpserver']);
        $view->setLayout($layout);
        $view->oauth = $data;
        // setDocument() from Joomla 4.4; the public property before that.
        if (method_exists($view, 'setDocument')) {
            $view->setDocument($app->getDocument());
        } else {
            $view->document = $app->getDocument();
        }
        $view->display();
    }

    private function serveOAuthEndpoint(string $endpoint): void
    {
        $app = Factory::getApplication();
        $params = OAuthServiceFactory::params();

        $response = $app->getName() === 'site' && OAuthConfig::isEnabled($params)
            ? OAuthServiceFactory::endpoints($params)->handle($endpoint, OAuthServiceFactory::currentRequest($app->input, $params))
            : OAuthHttpResponse::json(404, ['error' => 'not_found', 'error_description' => 'OAuth is not enabled on this site']);

        $response->send();
        $app->close();
    }
}
