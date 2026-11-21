<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Mcpserver\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthConfig;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthEndpoints;
use Joomla\Component\Mcpserver\Administrator\Service\OAuthServiceFactory;
use Joomla\Event\SubscriberInterface;

/**
 * Answers the OAuth URLs that cannot be component URLs.
 *
 * MCP clients look for authorisation server metadata at the site root's
 * /.well-known/ path (RFC 8414, RFC 9728), and an OAuth issuer may not carry a
 * query string, so com_mcpserver cannot serve these itself. The /mcp-oauth/
 * endpoints are clean paths for the same reason (see OAuthConfig).
 *
 * Runs at onAfterInitialise, before routing, and only claims a request whose
 * path it recognises while OAuth is enabled; everything else passes through.
 */
final class Mcpserver extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        $app = Factory::getApplication();

        // The component's classes are absent if it was removed without this plugin.
        if (!$app->isClient('site') || !class_exists(OAuthConfig::class)) {
            return;
        }

        $route = OAuthConfig::routeForPath(Uri::getInstance()->getPath(), Uri::root(true));
        if ($route === null) {
            return;
        }

        try {
            // Loads the component's Composer autoloader (RateLimiter needs PSR-16).
            $app->bootComponent('com_mcpserver');
        } catch (\Throwable) {
            return;
        }

        $params = OAuthServiceFactory::params();
        if (!OAuthConfig::isEnabled($params)) {
            return;
        }

        [$endpoint, $suffix] = $route;

        if ($endpoint === OAuthEndpoints::AUTHORIZE) {
            // The consent page is a component page: it needs the session, the
            // login redirect and the site template. Hand the request over intact.
            $query = (string) Uri::getInstance()->getQuery();
            header('Cache-Control: no-store');
            header('Location: ' . OAuthServiceFactory::config($params)->componentUrl('oauth.authorize') . ($query !== '' ? '&' . $query : ''), true, 302);
            $app->close();

            return;
        }

        $request = OAuthServiceFactory::currentRequest($app->input, $params);

        // RFC 9728 §3.1: /.well-known/oauth-protected-resource/<path> asks
        // about the resource at <path> on this origin. The path has lost the
        // MCP endpoint's query string; OAuthConfig maps it back.
        if ($endpoint === OAuthEndpoints::PROTECTED_RESOURCE && $suffix !== '' && $request->queryParam('resource') === null) {
            $request = $request->withQueryParam(
                'resource',
                OAuthServiceFactory::config($params)->resourceForWellKnownPath(Uri::getInstance()->toString(['scheme', 'host', 'port']), $suffix)
            );
        }

        OAuthServiceFactory::endpoints($params)->handle($endpoint, $request)->send();
        $app->close();
    }
}
