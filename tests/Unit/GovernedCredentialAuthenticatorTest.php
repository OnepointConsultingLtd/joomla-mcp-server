<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use DateTimeImmutable;
use Joomla\Component\Mcpserver\Administrator\Service\{
    CredentialCipher,
    CredentialRecord,
    CredentialStoreInterface,
    GovernedCredentialAuthenticator,
    McpCredential
};
use PHPUnit\Framework\TestCase;

final class GovernanceTestStore implements CredentialStoreInterface
{
    public ?CredentialRecord $record = null;

    public int $touches = 0;

    public ?\Throwable $touchFailure = null;

    public function findBySelector(string $s): ?CredentialRecord
    {
        return $this->record?->selector === $s ? $this->record : null;
    }

    public function touchLastUsed(int $id): void
    {
        ++$this->touches;

        if ($this->touchFailure !== null) {
            throw $this->touchFailure;
        }
    }
}

final class GovernedCredentialAuthenticatorTest extends TestCase
{
    private const NOW = '2026-09-12 12:00:00';

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
    }

    /**
     * Build a store holding one credential with the given status/revoked/expiry,
     * plus the issued token needed to authenticate against it.
     *
     * @return array{store: GovernanceTestStore, cipher: CredentialCipher, token: string}
     */
    private function scenario(
        string $status = 'active',
        ?DateTimeImmutable $revoked = null,
        ?DateTimeImmutable $expires = null
    ): array {
        $issued = McpCredential::issue();
        $cipher = $this->cipher();
        $store = new GovernanceTestStore();
        // Named arguments deliberately: CredentialRecord takes $expires before
        // $revoked, and both are nullable DateTimeImmutable, so a positional
        // swap is silent.
        $store->record = new CredentialRecord(
            id: 1,
            selector: $issued['selector'],
            userId: 2,
            name: 'User',
            verifier: $issued['verifier'],
            encryptedToken: $cipher->encrypt('api-token'),
            status: $status,
            expires: $expires,
            revoked: $revoked
        );

        return ['store' => $store, 'cipher' => $cipher, 'token' => $issued['token']];
    }

    private function authenticate(array $s, ?string $token = null, ?string $now = null): mixed
    {
        $auth = new GovernedCredentialAuthenticator($s['store'], $s['cipher']);

        return $auth->authenticateBearer(
            'Bearer ' . ($token ?? $s['token']),
            new DateTimeImmutable($now ?? self::NOW)
        );
    }

    public function testOnlyActiveUnexpiredCredentialProducesPrincipal(): void
    {
        $s = $this->scenario(expires: new DateTimeImmutable('2026-09-13 12:00:00'));

        $principal = $this->authenticate($s);

        $this->assertSame(2, $principal->userId);
        $this->assertSame('api-token', $principal->apiToken());
        $this->assertSame(1, $s['store']->touches);
    }

    public function testUnknownCredentialFailsWithoutTouchingStore(): void
    {
        $issued = McpCredential::issue();
        $s = ['store' => new GovernanceTestStore(), 'cipher' => $this->cipher(), 'token' => $issued['token']];

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    /**
     * The single most important assertion here: without it, deleting the secret
     * check entirely leaves the suite green and any secret authenticates
     * against a known selector.
     */
    public function testWrongSecretIsRejectedForAKnownSelector(): void
    {
        $s = $this->scenario();
        $other = McpCredential::issue();
        $forged = 'mcp_' . explode('.', substr($s['token'], 4))[0] . '.' . explode('.', substr($other['token'], 4))[1];

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s, $forged);
    }

    public function testRevokedCredentialIsRejected(): void
    {
        $s = $this->scenario('revoked', new DateTimeImmutable('2026-09-11 12:00:00'));

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testRevokedTimestampAloneIsRejected(): void
    {
        // status left 'active' so this pins the revoked-timestamp clause
        // independently of the status clause.
        $s = $this->scenario('active', new DateTimeImmutable('2026-09-11 12:00:00'));

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testNonActiveStatusIsRejected(): void
    {
        $s = $this->scenario('suspended');

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testExpiredCredentialIsRejected(): void
    {
        $s = $this->scenario(expires: new DateTimeImmutable('2026-09-12 11:59:59'));

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testCredentialExpiringExactlyNowIsRejected(): void
    {
        // Boundary: expiry is inclusive, so expires == now must not authenticate.
        $s = $this->scenario(expires: new DateTimeImmutable(self::NOW));

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testCredentialExpiringOneSecondFromNowStillAuthenticates(): void
    {
        $s = $this->scenario(expires: new DateTimeImmutable('2026-09-12 12:00:01'));

        $this->assertSame(2, $this->authenticate($s)->userId);
    }

    public function testDecryptionFailureUnderARotatedKeyIsRejected(): void
    {
        // The post-restore disaster: ciphertext is intact, the site secret moved.
        $s = $this->scenario(expires: new DateTimeImmutable('2026-09-13 12:00:00'));
        $s['cipher'] = new CredentialCipher('different-site-secret', base64_encode('component-salt-0123456789'));

        $this->expectException(\RuntimeException::class);
        $this->authenticate($s);
    }

    public function testFailureMessageIsIdenticalForUnknownAndRevoked(): void
    {
        $unknownMessage = null;
        $revokedMessage = null;

        $issued = McpCredential::issue();
        try {
            $this->authenticate(
                ['store' => new GovernanceTestStore(), 'cipher' => $this->cipher(), 'token' => $issued['token']]
            );
        } catch (\RuntimeException $e) {
            $unknownMessage = $e->getMessage();
        }

        try {
            $this->authenticate($this->scenario('revoked', new DateTimeImmutable('2026-09-11 12:00:00')));
        } catch (\RuntimeException $e) {
            $revokedMessage = $e->getMessage();
        }

        $this->assertNotNull($unknownMessage);
        $this->assertSame($unknownMessage, $revokedMessage, 'rejection reasons must not be distinguishable');
    }

    public function testLastUsedFailureDoesNotDenyAnOtherwiseValidCredential(): void
    {
        $s = $this->scenario(expires: new DateTimeImmutable('2026-09-13 12:00:00'));
        $s['store']->touchFailure = new \RuntimeException('read-only replica');

        $principal = $this->authenticate($s);

        $this->assertSame(2, $principal->userId);
        $this->assertSame(1, $s['store']->touches);
    }
}
