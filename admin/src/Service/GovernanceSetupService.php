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
 * Encapsulates governed-mode enablement for the component: generating and
 * validating the credential salt, and persisting governed-mode parameters
 * atomically through an injected callback. Never touches configuration on
 * its own during construction or status reporting; a config change only
 * happens when enable() is explicitly called.
 */
final class GovernanceSetupService
{
    private const SALT_BYTE_LENGTH = 32;

    /**
     * @param callable(): array<string,mixed> $readParams    Reads current component params.
     * @param callable(array<string,mixed>): void $persistParams Persists params atomically.
     * @param callable(): string $secretProvider Resolves the Joomla application secret on demand.
     * @param callable(): int $countCredentials Counts stored credentials. Required so enable()
     *                                          can refuse to mint a salt that would orphan them.
     */
    public function __construct(
        private $readParams,
        private $persistParams,
        private $secretProvider,
        private $countCredentials,
    ) {
    }

    /**
     * Provision the credential salt, generating one if none exists yet.
     * Deliberately touches nothing else in the component configuration: the
     * metrics retention window is owned solely by Options > Monitoring &
     * Metrics (`metrics_retention_days`), so setup must not carry a second
     * copy of it that silently overwrites what the operator set there.
     *
     * Deliberately does not force
     * `governed_mode` on: the documented cutover flow is to provision the
     * salt first (so credentials can already be issued and encrypted) and
     * only flip Governed Mode on afterwards, once every client has its own
     * credential issued, via the component's own configuration form. This
     * call preserves whatever `governed_mode` value is already stored.
     *
     * Refuses to replace a salt that is already present but unreadable, and
     * refuses to generate a first salt while credentials already exist:
     * regenerating the salt changes the HKDF input, which makes every stored
     * token_ciphertext permanently undecryptable. That failure is silent at the
     * point of damage — clients only start returning 401 afterwards — so it has
     * to be refused here rather than reported later.
     */
    public function enable(): void
    {
        $params = ($this->readParams)();

        // A partial/empty params read must not be mistaken for "governed_mode
        // is off": persisting that would silently revert the site to shared
        // token behaviour while reporting success.
        if (!array_key_exists('governed_mode', $params)) {
            throw new \RuntimeException(
                'Component parameters could not be read; refusing to persist governance settings.'
            );
        }

        $storedSalt = $params['credential_salt'] ?? null;
        $hasStoredSalt = is_string($storedSalt) && trim($storedSalt) !== '';

        if ($hasStoredSalt && !$this->isValidSalt($storedSalt)) {
            throw new \RuntimeException(
                'A credential salt is already stored but could not be parsed. Generating a new one '
                . 'would permanently invalidate every issued MCP credential. Restore the salt from '
                . 'backup, or revoke all credentials before re-provisioning.'
            );
        }

        if (!$hasStoredSalt && ($this->countCredentials)() > 0) {
            throw new \RuntimeException(
                'Credentials already exist but no credential salt is stored. Generating one now '
                . 'would permanently invalidate them. Restore the salt from backup, or revoke all '
                . 'credentials before re-provisioning.'
            );
        }

        $salt = $hasStoredSalt ? $storedSalt : $this->generateSalt();

        ($this->persistParams)([
            'governed_mode' => (int) $params['governed_mode'],
            'credential_salt' => $salt,
        ]);
    }

    /**
     * @return array{configured:bool,salt_valid:bool,governed_active:bool,recovery_key_fingerprint:?string}
     */
    public function status(): array
    {
        $params = ($this->readParams)();
        $salt = $params['credential_salt'] ?? null;
        $saltValid = $this->isValidSalt($salt);
        $governedActive = (int) ($params['governed_mode'] ?? 0) === 1;

        return [
            'configured' => $saltValid && $governedActive,
            'salt_valid' => $saltValid,
            'governed_active' => $governedActive,
            'recovery_key_fingerprint' => $saltValid ? $this->fingerprint($salt) : null,
        ];
    }

    private function isValidSalt(mixed $salt): bool
    {
        if (!is_string($salt) || trim($salt) === '') {
            return false;
        }

        $decoded = base64_decode($salt, true);

        return $decoded !== false && $decoded !== '';
    }

    private function generateSalt(): string
    {
        return base64_encode(random_bytes(self::SALT_BYTE_LENGTH));
    }

    private function fingerprint(string $salt): string
    {
        $keyMaterial = new GovernanceKeyMaterial($this->secretProvider, $salt);

        return $keyMaterial->fingerprint();
    }
}
