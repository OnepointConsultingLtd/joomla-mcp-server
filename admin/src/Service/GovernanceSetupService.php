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
    private const MIN_RETENTION_DAYS = 1;
    private const MAX_RETENTION_DAYS = 3650;

    /**
     * @param callable(): array<string,mixed> $readParams    Reads current component params.
     * @param callable(array<string,mixed>): void $persistParams Persists params atomically.
     * @param callable(): string $secretProvider Resolves the Joomla application secret on demand.
     */
    public function __construct(
        private $readParams,
        private $persistParams,
        private $secretProvider,
    ) {
    }

    /**
     * Provision the credential salt (generating one if none exists yet) and
     * persist the metrics retention window. Deliberately does not force
     * `governed_mode` on: the documented cutover flow is to provision the
     * salt first (so credentials can already be issued and encrypted) and
     * only flip Governed Mode on afterwards, once every client has its own
     * credential issued, via the component's own configuration form. This
     * call preserves whatever `governed_mode` value is already stored.
     */
    public function enable(int $retentionDays): void
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS || $retentionDays > self::MAX_RETENTION_DAYS) {
            throw new \InvalidArgumentException(sprintf(
                'Retention days must be between %d and %d',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS
            ));
        }

        $params = ($this->readParams)();
        $salt = $this->isValidSalt($params['credential_salt'] ?? null)
            ? $params['credential_salt']
            : $this->generateSalt();

        ($this->persistParams)([
            'governed_mode' => (int) ($params['governed_mode'] ?? 0),
            'credential_salt' => $salt,
            'metrics_retention_days' => $retentionDays,
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
