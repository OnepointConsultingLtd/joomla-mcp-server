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
 * Holds the component's governed-mode key material: an injected callable that
 * resolves the Joomla application secret and the component's own salt. The
 * secret is never read until a cipher is actually requested, and derivation
 * fails closed (throws) on any missing or malformed input instead of
 * silently producing a weak or absent key.
 */
final class GovernanceKeyMaterial
{
    /**
     * @param callable(): string $secretProvider Resolves the Joomla application secret on demand.
     * @param string             $componentSalt  Base64-encoded component salt.
     */
    public function __construct(
        private $secretProvider,
        private string $componentSalt,
    ) {
        if (trim($this->componentSalt) === '') {
            throw new \RuntimeException('Component salt must not be empty');
        }

        $decoded = base64_decode($this->componentSalt, true);
        if ($decoded === false || $decoded === '') {
            throw new \RuntimeException('Component salt must be valid base64');
        }
    }

    public function createCipher(): CredentialCipher
    {
        $secret = (string) ($this->secretProvider)();

        return new CredentialCipher($secret, $this->componentSalt);
    }

    /**
     * Return a deterministic, non-reversible identifier for this installation's
     * governed credential encryption key. This can be compared across restores
     * without exposing either the Joomla secret or the component salt.
     */
    public function fingerprint(): string
    {
        $secret = (string) ($this->secretProvider)();
        if ($secret === '') {
            throw new \RuntimeException('Joomla application secret must not be empty');
        }

        $salt = base64_decode($this->componentSalt, true);
        if ($salt === false || $salt === '') {
            throw new \RuntimeException('Component salt must be valid base64');
        }

        $key = hash_hkdf('sha256', $secret, 32, 'com_mcpserver:recovery-fingerprint:v1', $salt);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Unable to derive credential fingerprint');
        }

        return hash_hmac('sha256', 'com_mcpserver:credential-encryption-identity:v1', $key);
    }
}
