<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Psr\Log\LoggerInterface;

/**
 * Builds RestClient instances against the component's configured base URL,
 * SSL and resolve_ip settings. In governed mode each authenticated principal
 * gets a RestClient bound to their own Joomla API token instead of the
 * shared configured token.
 */
class RestClientFactory
{
    private Registry $params;
    private LoggerInterface $logger;

    public function __construct(Registry $params, LoggerInterface $logger)
    {
        $this->params = $params;
        $this->logger = $logger;
    }

    public function createForPrincipal(AuthenticatedPrincipal $principal): RestClient
    {
        return $this->create($principal->joomlaApiToken);
    }

    public function createShared(): RestClient
    {
        return $this->create((string) $this->params->get('api_token', ''));
    }

    private function create(string $apiToken): RestClient
    {
        $baseUrl = rtrim((string) $this->params->get('base_url', ''), '/');
        $verifySsl = (bool) $this->params->get('verify_ssl', true);
        $resolveIp = trim((string) $this->params->get('resolve_ip', ''));

        if ($baseUrl === '') {
            $baseUrl = rtrim(Uri::root(), '/');
        }

        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $baseUrl = $scheme . '://' . $host . '/' . ltrim($baseUrl, '/');
        }

        return new RestClient(
            $baseUrl,
            $apiToken ?: null,
            $this->logger,
            $verifySsl,
            $resolveIp !== '' ? $resolveIp : null
        );
    }
}
