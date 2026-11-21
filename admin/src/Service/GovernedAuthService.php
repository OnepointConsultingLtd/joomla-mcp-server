<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\Input\Input;

/**
 * Authenticates governed-mode MCP requests using stored credentials instead
 * of the single shared bearer token.
 *
 * Two kinds of bearer token are accepted: a credential bearer token
 * (`mcp_...`), and, when OAuth is enabled, an OAuth access token (`mcpat_...`)
 * whose grant is bound to a credential. Either way the request runs as that
 * credential, with the same expiry and revocation checks.
 */
final class GovernedAuthService
{
    /**
     * @param Input $server The request's server input (`$app->input->server`), read via AuthorizationHeader.
     */
    public function __construct(
        private GovernedCredentialAuthenticator $authenticator,
        private Input $server,
        private ?OAuthAccessTokenAuthenticator $oauthAuthenticator = null,
    ) {
    }

    /**
     * @return AuthenticatedPrincipal|array{error:string,code:int}
     */
    public function authenticate(): AuthenticatedPrincipal|array
    {
        $header = AuthorizationHeader::read($this->server);

        if (empty($header)) {
            return ['error' => 'Missing bearer token in Authorization header', 'code' => JsonRpc::UNAUTHORIZED];
        }

        // UTC explicitly: credential expiries are stored in UTC, so an ambient
        // date.timezone would shift every expiry check by the offset.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = AuthorizationHeader::bearerToken($header) ?? '';

        if ($this->oauthAuthenticator !== null && OAuthAccessTokenAuthenticator::isAccessToken($token)) {
            return $this->authenticateOAuth($token, $now);
        }

        try {
            return $this->authenticator->authenticateCredentialBearerToken($token, $now);
        } catch (\RuntimeException) {
            return ['error' => 'Invalid or expired MCP credential', 'code' => JsonRpc::UNAUTHORIZED];
        }
    }

    /**
     * @return AuthenticatedPrincipal|array{error:string,code:int}
     */
    private function authenticateOAuth(string $token, DateTimeImmutable $now): AuthenticatedPrincipal|array
    {
        try {
            $grant = $this->oauthAuthenticator->authenticate($token, $now);
            $principal = $this->authenticator->authenticateBoundCredential($grant->credentialSelector, $now);
        } catch (\RuntimeException) {
            return ['error' => OAuthAccessTokenAuthenticator::FAILURE, 'code' => JsonRpc::UNAUTHORIZED];
        }

        // The grant recorded who consented; the credential must still be theirs.
        if ($principal->userId !== $grant->userId || $principal->credentialId !== $grant->credentialId) {
            return ['error' => OAuthAccessTokenAuthenticator::FAILURE, 'code' => JsonRpc::UNAUTHORIZED];
        }

        return $principal;
    }
}
