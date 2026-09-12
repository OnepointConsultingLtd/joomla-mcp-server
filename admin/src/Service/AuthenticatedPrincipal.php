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
 * The identity behind an authenticated governed request: which Joomla user,
 * via which credential, and the decrypted Joomla API token that their tool
 * calls execute with.
 *
 * That token is the site's most sensitive runtime value, and this object is
 * passed to the audit service, the action logger, the tool authorizer, the
 * cache and RpcService. It is therefore deliberately awkward to leak: the
 * token is private and reachable only through apiToken(), so `grep apiToken()`
 * enumerates every reader; __debugInfo() masks it for var_dump(); and
 * serialization is refused outright so it can never reach a session or cache
 * store. Keep it that way — a public property here would put a live
 * site-admin API token into any log line that dumps a principal.
 */
final class AuthenticatedPrincipal
{
    public function __construct(
        public readonly int $credentialId,
        public readonly string $selector,
        public readonly int $userId,
        public readonly string $credentialName,
        private readonly string $joomlaApiToken,
    ) {
        if ($credentialId <= 0 || $userId <= 0 || $selector === '' || $joomlaApiToken === '') {
            throw new \InvalidArgumentException('Invalid authenticated principal');
        }
    }

    /**
     * The decrypted Joomla Web Services API token this principal acts with.
     *
     * The only way out of this object. Callers must not store, log, or copy
     * the return value beyond the RestClient they build from it.
     */
    public function apiToken(): string
    {
        return $this->joomlaApiToken;
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'credentialId' => $this->credentialId,
            'selector' => $this->selector,
            'userId' => $this->userId,
            'credentialName' => $this->credentialName,
            'joomlaApiToken' => '***redacted***',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('AuthenticatedPrincipal must not be serialized: it carries a plaintext API token.');
    }
}
