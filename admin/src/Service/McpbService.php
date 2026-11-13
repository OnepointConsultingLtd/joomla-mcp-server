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

/**
 * Builds a personalised Claude Desktop extension bundle (.mcpb) for this site:
 * the endpoint URL is pre-filled and the connector is named after the site.
 * The bearer token is deliberately never embedded in the generated file.
 */
class McpbService
{
    private const TEMPLATE_DIR = '/components/com_mcpserver/mcpb';

    private Registry $params;

    public function __construct(Registry $params)
    {
        $this->params = $params;
    }

    /**
     * Build the bundle and return the path of the temporary archive.
     * The caller is responsible for streaming and deleting the file.
     *
     * @throws \RuntimeException When the archive cannot be produced
     */
    public function buildBundle(string $siteName): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is not available.');
        }

        // All three inputs ship in the component's admin folder so a missing or
        // unreadable site-installed bridge can never break bundle generation.
        $manifestPath = JPATH_ADMINISTRATOR . self::TEMPLATE_DIR . '/manifest.json';
        $iconPath     = JPATH_ADMINISTRATOR . self::TEMPLATE_DIR . '/icon.png';
        $bridgePath   = JPATH_ADMINISTRATOR . self::TEMPLATE_DIR . '/server/mcp-http-bridge.js';

        foreach ([$manifestPath, $iconPath, $bridgePath] as $sourcePath) {
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                throw new \RuntimeException('Bundle source file is missing or unreadable: ' . $sourcePath);
            }
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        // The display name is what Claude Desktop shows, and what it names the
        // server's log file after: a stray double space in the site name
        // ("Meditation Center  Manhattan") is easy to miss and hard to match.
        $siteName = trim((string) preg_replace('/\s+/u', ' ', $siteName));
        if ($siteName !== '') {
            $manifest['display_name'] = $siteName;
        }
        // Claude Desktop identifies an extension by author and name, so a shared
        // name would make each site's bundle replace — and drop the token of —
        // any other site's bundle already installed.
        $manifest['name'] = $this->extensionName((string) $manifest['name']);
        $manifest['user_config']['endpoint_url']['default'] = $this->endpointUrl();

        $bundlePath = $this->createTempFile();
        if ($bundlePath === false) {
            throw new \RuntimeException('Could not create a temporary file for the bundle archive.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($bundlePath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the bundle archive.');
        }

        // addFile() defers reading to close(), so both must be checked or a
        // missing asset would surface as a cryptically broken download.
        $assembled = $zip->addFromString(
            'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        )
            && $zip->addFile($iconPath, 'icon.png')
            && $zip->addFile($bridgePath, 'server/mcp-http-bridge.js');
        $closed = $zip->close();

        if (!$assembled || !$closed) {
            @unlink($bundlePath);
            throw new \RuntimeException('Could not assemble the bundle archive.');
        }

        return $bundlePath;
    }

    protected function createTempFile(): string|false
    {
        return tempnam(sys_get_temp_dir(), 'mcpb');
    }

    /**
     * The site's public RPC endpoint URL: the configured Base URL when set,
     * otherwise derived from the live request. Shared by the bundle builder
     * and the client configuration card so the two can never disagree.
     */
    public function endpointUrl(): string
    {
        return $this->baseUrl() . '/index.php?option=com_mcpserver&task=rpc.handle';
    }

    /**
     * This site's host and path as a lowercase slug, e.g. example-com-shop:
     * stable for one site and distinct between sites, including several Joomla
     * installs on one host. Clients key their servers by name, so every name
     * offered for this site is built from it. Empty when no URL is known.
     */
    public function siteSlug(): string
    {
        $site = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $this->baseUrl());

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($site)), '-');
    }

    /**
     * The template's extension name suffixed with the site slug, e.g.
     * joomla-mcp-server-example-com-shop.
     */
    private function extensionName(string $baseName): string
    {
        $slug = $this->siteSlug();

        return $slug === '' ? $baseName : $baseName . '-' . $slug;
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) $this->params->get('base_url', ''), '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim(Uri::root(), '/');
        }

        return $baseUrl;
    }
}
