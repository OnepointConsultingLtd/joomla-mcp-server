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
use Psr\Log\LoggerInterface;

/**
 * Verifies an `mcp_<selector>.<secret>` bearer token against the credential
 * store and returns the AuthenticatedPrincipal it identifies.
 *
 * Fails closed on every negative branch, and deliberately does the same amount
 * of work whether or not the selector exists — see verifySecret() below.
 */
final class GovernedCredentialAuthenticator
{
    /**
     * Single opaque message for every rejection. The caller must not be able to
     * tell an unknown selector from a revoked, expired, or wrong-secret one.
     */
    private const FAILURE = 'Invalid or expired MCP credential';

    /**
     * A real bcrypt hash of a value nobody holds, verified against when the
     * selector is unknown. See verifySecret().
     *
     * The cost here must track password_hash(..., PASSWORD_DEFAULT) in
     * McpCredential::issue(); if PHP's default cost or algorithm changes, this
     * constant has to be regenerated or the two paths drift apart in timing.
     */
    private const DUMMY_VERIFIER = '$2y$10$45dIGQIfZGND4Y24Zc5vDOR4kIxZijEC6b.Z5fHTiozeGWFAafijm';

    public function __construct(
        private CredentialStoreInterface $store,
        private CredentialCipher $cipher,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function authenticateBearer(string $header, callable|DateTimeImmutable $now): AuthenticatedPrincipal
    {
        $parsed = McpCredential::parseBearer($header);
        $record = $parsed === null ? null : $this->store->findBySelector($parsed['selector']);

        $validSecret = $this->verifySecret($parsed['secret'] ?? '', $record);
        $time = $now instanceof DateTimeImmutable ? $now : $now();

        if (!$this->isUsable($record, $time) || !$validSecret) {
            throw new \RuntimeException(self::FAILURE);
        }

        try {
            $token = $this->cipher->decrypt($record->encryptedToken);
        } catch (\RuntimeException) {
            throw new \RuntimeException(self::FAILURE);
        }

        if ($token === '') {
            throw new \RuntimeException(self::FAILURE);
        }

        // Bookkeeping only. A failure to stamp last_used must never revoke an
        // authentication that has already succeeded — without this guard a
        // read-only replica or a locked table turns every governed request into
        // "Invalid or expired MCP credential".
        try {
            $this->store->touchLastUsed($record->id);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not update last_used for MCP credential', [
                'credential_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }

        return new AuthenticatedPrincipal(
            $record->id,
            $record->selector,
            $record->userId,
            $record->name,
            $token
        );
    }

    /**
     * Whether this record may authenticate at $time.
     *
     * A null $expires means the credential never expires; no production
     * issuance path writes one, but the column is nullable.
     */
    private function isUsable(?CredentialRecord $record, DateTimeImmutable $time): bool
    {
        if ($record === null) {
            return false;
        }

        if ($record->status !== 'active' || $record->revoked !== null) {
            return false;
        }

        return $record->expires === null || $record->expires > $time;
    }

    /**
     * Verify the presented secret, doing the same bcrypt work for an unknown
     * selector as for a known one.
     *
     * This is called unconditionally, before the usability checks above, and
     * falls back to DUMMY_VERIFIER when there is no record. Both details are
     * deliberate: short-circuiting on an unknown selector would let an attacker
     * enumerate valid selectors by response time. Do not "simplify" this into
     * the guard expression.
     */
    private function verifySecret(string $secret, ?CredentialRecord $record): bool
    {
        return McpCredential::verify($secret, $record?->verifier ?? self::DUMMY_VERIFIER);
    }
}
