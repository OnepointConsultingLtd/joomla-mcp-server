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
 * The two discovery documents MCP clients read before authorising.
 */
final class OAuthMetadata
{
    private const AUTH_METHODS = [OAuthClient::AUTH_NONE, OAuthClient::AUTH_SECRET_POST, OAuthClient::AUTH_SECRET_BASIC];

    /**
     * Authorization Server Metadata (RFC 8414).
     *
     * @return array<string,mixed>
     */
    public static function authorizationServer(OAuthConfig $config): array
    {
        $metadata = [
            'issuer' => $config->issuer(),
            'authorization_endpoint' => $config->endpoint('authorize'),
            'token_endpoint' => $config->endpoint('token'),
            'revocation_endpoint' => $config->endpoint('revoke'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'scopes_supported' => [OAuthServer::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
        ];

        // Both ways a client can introduce itself are governed by one option.
        if ($config->registrationEnabled) {
            $metadata['registration_endpoint'] = $config->endpoint('register');
            $metadata['client_id_metadata_document_supported'] = true;
        }

        return $metadata;
    }

    /**
     * Protected Resource Metadata (RFC 9728).
     *
     * @param string|null $resource The resource the client asked about; the MCP endpoint when absent or not ours.
     *
     * @return array<string,mixed>
     */
    public static function protectedResource(OAuthConfig $config, ?string $resource, string $resourceName): array
    {
        return [
            'resource' => $resource !== null && $config->ownsResource($resource) ? $resource : $config->defaultResource(),
            'authorization_servers' => [$config->issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [OAuthServer::SCOPE],
            'resource_name' => $resourceName,
        ];
    }
}
