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

/**
 * Authenticates governed-mode MCP requests using stored credentials instead
 * of the single shared bearer token.
 */
final class GovernedAuthService
{
    /**
     * @param callable(string $name, string $default): string $serverInput Reads a server variable, e.g. $input->server->getString(...).
     */
    public function __construct(
        private GovernedCredentialAuthenticator $authenticator,
        private $serverInput,
    ) {
    }

    /**
     * @return AuthenticatedPrincipal|array{error:string,code:int}
     */
    public function authenticate(): AuthenticatedPrincipal|array
    {
        $header = ($this->serverInput)('HTTP_AUTHORIZATION', '');
        if (empty($header)) {
            $header = ($this->serverInput)('REDIRECT_HTTP_AUTHORIZATION', '');
        }

        if (empty($header)) {
            return ['error' => 'Missing bearer token in Authorization header', 'code' => JsonRpc::UNAUTHORIZED];
        }

        try {
            return $this->authenticator->authenticateBearer($header, new DateTimeImmutable());
        } catch (\RuntimeException) {
            return ['error' => 'Invalid or expired MCP credential', 'code' => JsonRpc::UNAUTHORIZED];
        }
    }
}
