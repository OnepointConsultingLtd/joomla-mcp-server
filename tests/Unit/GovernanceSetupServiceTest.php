<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\GovernanceSetupService;
use PHPUnit\Framework\TestCase;

class GovernanceSetupServiceTest extends TestCase
{
    private const SITE_SECRET = 'joomla-site-secret-value';

    /**
     * @param array<string,mixed> $initialParams
     * @param int $credentialCount Stored credentials; enable() refuses to mint a
     *                             first salt while any exist.
     * @return array{service: GovernanceSetupService, getPersisted: callable(): ?array<string,mixed>}
     */
    private function makeService(array $initialParams, int $credentialCount = 0): array
    {
        $store = $initialParams;
        $persisted = null;

        $service = new GovernanceSetupService(
            static function () use (&$store): array {
                return $store;
            },
            static function (array $params) use (&$store, &$persisted): void {
                $persisted = $params;
                $store = array_merge($store, $params);
            },
            static fn (): string => self::SITE_SECRET,
            static fn (): int => $credentialCount,
        );

        return [
            'service' => $service,
            'getPersisted' => static function () use (&$persisted): ?array {
                return $persisted;
            },
        ];
    }

    public function testEnableGeneratesSaltWhenNoneExists(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $persisted = ($refs['getPersisted'])();
        $this->assertNotNull($persisted);
        $this->assertSame(0, $persisted['governed_mode'], 'enable() must not force governed_mode on');
        $this->assertIsString($persisted['credential_salt']);
        $decoded = base64_decode($persisted['credential_salt'], true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testEnablePreservesGovernedModeWhenAlreadyActive(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 1,
            'credential_salt' => base64_encode(random_bytes(32)),
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $persisted = ($refs['getPersisted'])();
        $this->assertSame(1, $persisted['governed_mode'], 'enable() must not disable an already-active governed mode');
    }

    public function testEnableProvisionsSaltBeforeCutoverWithoutActivatingGovernedMode(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $status = $service->status();
        $this->assertTrue($status['salt_valid'], 'the salt must be provisioned so credentials can already be issued/encrypted');
        $this->assertFalse($status['governed_active'], 'governed mode must remain disabled until cutover is completed separately');
        $this->assertFalse($status['configured']);
    }

    public function testEnableRetainsExistingValidSalt(): void
    {
        $existingSalt = base64_encode(random_bytes(32));
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => $existingSalt,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $persisted = ($refs['getPersisted'])();
        $this->assertSame($existingSalt, $persisted['credential_salt']);
    }

    public function testEnableRefusesToReplaceAnUnreadableStoredSalt(): void
    {
        // Replacing a present-but-unparseable salt silently orphans every
        // stored token_ciphertext, so enable() must refuse rather than mint a
        // fresh one and report success.
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => 'not valid base64!!',
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        try {
            $service->enable();
            $this->fail('enable() should refuse to overwrite an unreadable salt');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already stored', $e->getMessage());
        }

        $this->assertNull(($refs['getPersisted'])(), 'nothing may be persisted on refusal');
    }

    public function testEnableRefusesToMintAFirstSaltWhileCredentialsExist(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ], 3);
        $service = $refs['service'];

        try {
            $service->enable();
            $this->fail('enable() should refuse to mint a salt that orphans credentials');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Credentials already exist', $e->getMessage());
        }

        $this->assertNull(($refs['getPersisted'])(), 'nothing may be persisted on refusal');
    }

    public function testEnableRefusesWhenParamsCannotBeRead(): void
    {
        // An empty read must not be mistaken for governed_mode = 0, which would
        // silently revert the site to shared-token mode with a success message.
        $refs = $this->makeService([]);
        $service = $refs['service'];

        $this->expectException(\RuntimeException::class);
        $service->enable();
    }

    public function testEnableGeneratesAFirstSaltWhenNoCredentialsExist(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $persisted = ($refs['getPersisted'])();
        $decoded = base64_decode($persisted['credential_salt'], true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testEnableLeavesMetricsRetentionUntouched(): void
    {
        // metrics_retention_days has a single home: Options > Monitoring &
        // Metrics. Salt provisioning must not write a second copy of it, or
        // pressing the setup button would silently reset the operator's
        // configured retention window to whatever the setup form defaulted to.
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable();

        $persisted = ($refs['getPersisted'])();
        $this->assertNotNull($persisted);
        $this->assertArrayNotHasKey(
            'metrics_retention_days',
            $persisted,
            'enable() must not persist a retention window; that setting is owned by the component options.'
        );
    }

    public function testStatusReportsConfiguredWhenGovernedActiveAndSaltValid(): void
    {
        $salt = base64_encode(random_bytes(32));
        $refs = $this->makeService([
            'governed_mode' => 1,
            'credential_salt' => $salt,
            'metrics_retention_days' => 90,
        ]);
        $service = $refs['service'];

        $status = $service->status();

        $this->assertTrue($status['configured']);
        $this->assertTrue($status['salt_valid']);
        $this->assertTrue($status['governed_active']);
        $this->assertIsString($status['recovery_key_fingerprint']);
        $this->assertNotSame('', $status['recovery_key_fingerprint']);
    }

    public function testStatusRedactsSaltAndSecretFromReportedFields(): void
    {
        $salt = base64_encode(random_bytes(32));
        $refs = $this->makeService([
            'governed_mode' => 1,
            'credential_salt' => $salt,
            'metrics_retention_days' => 90,
        ]);
        $service = $refs['service'];

        $status = $service->status();

        foreach ($status as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($salt, $value);
                $this->assertStringNotContainsString(self::SITE_SECRET, $value);
            }
        }
        $this->assertArrayNotHasKey('credential_salt', $status);
        $this->assertArrayNotHasKey('secret', $status);
    }

    public function testStatusFingerprintIsNullWhenSaltIsInvalid(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => 'not valid base64!!',
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $status = $service->status();

        $this->assertFalse($status['salt_valid']);
        $this->assertFalse($status['configured']);
        $this->assertNull($status['recovery_key_fingerprint']);
    }

    public function testStatusFingerprintIsDeterministicForSameSalt(): void
    {
        $salt = base64_encode(random_bytes(32));
        $refs = $this->makeService([
            'governed_mode' => 1,
            'credential_salt' => $salt,
            'metrics_retention_days' => 90,
        ]);
        $service = $refs['service'];

        $first = $service->status()['recovery_key_fingerprint'];
        $second = $service->status()['recovery_key_fingerprint'];

        $this->assertSame($first, $second);
    }

    public function testStatusFingerprintIsStableAcrossServiceInstances(): void
    {
        $salt = base64_encode(random_bytes(32));
        $first = $this->makeService(['governed_mode' => 1, 'credential_salt' => $salt]);
        $second = $this->makeService(['governed_mode' => 1, 'credential_salt' => $salt]);

        $this->assertSame(
            $first['service']->status()['recovery_key_fingerprint'],
            $second['service']->status()['recovery_key_fingerprint']
        );
    }

    public function testStatusFingerprintChangesWithSalt(): void
    {
        $first = $this->makeService(['governed_mode' => 1, 'credential_salt' => base64_encode(random_bytes(32))]);
        $second = $this->makeService(['governed_mode' => 1, 'credential_salt' => base64_encode(random_bytes(32))]);

        $this->assertNotSame(
            $first['service']->status()['recovery_key_fingerprint'],
            $second['service']->status()['recovery_key_fingerprint']
        );
    }

    public function testStatusNotConfiguredWhenGovernedModeDisabledDespiteValidSalt(): void
    {
        $salt = base64_encode(random_bytes(32));
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => $salt,
            'metrics_retention_days' => 90,
        ]);
        $service = $refs['service'];

        $status = $service->status();

        $this->assertFalse($status['governed_active']);
        $this->assertFalse($status['configured']);
        $this->assertTrue($status['salt_valid']);
    }
}
