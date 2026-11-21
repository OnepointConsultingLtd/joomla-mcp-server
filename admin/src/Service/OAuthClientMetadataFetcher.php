<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Retrieves a Client ID Metadata Document: the JSON a client publishes at the
 * https URL it uses as its client_id (draft-ietf-oauth-client-id-metadata-document,
 * the MCP authorization spec's preferred way for clients to identify themselves).
 */
interface OAuthClientMetadataFetcher
{
    /**
     * @return array<string,mixed> The decoded document.
     *
     * @throws \RuntimeException When the document cannot be fetched, is not
     *                           a JSON object, or the URL may not be fetched.
     */
    public function fetch(string $url): array;
}
