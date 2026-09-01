<?php

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
     * @return array{service: GovernanceSetupService, getPersisted: callable(): ?array<string,mixed>}
     */
    private function makeService(array $initialParams): array
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

        $service->enable(90);

        $persisted = ($refs['getPersisted'])();
        $this->assertNotNull($persisted);
        $this->assertSame(0, $persisted['governed_mode'], 'enable() must not force governed_mode on');
        $this->assertSame(90, $persisted['metrics_retention_days']);
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

        $service->enable(45);

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

        $service->enable(30);

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

        $service->enable(365);

        $persisted = ($refs['getPersisted'])();
        $this->assertSame($existingSalt, $persisted['credential_salt']);
    }

    public function testEnableReplacesInvalidSaltOnly(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => 'not valid base64!!',
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $service->enable(30);

        $persisted = ($refs['getPersisted'])();
        $this->assertNotSame('not valid base64!!', $persisted['credential_salt']);
        $decoded = base64_decode($persisted['credential_salt'], true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testEnableRejectsRetentionDaysBelowRangeAndDoesNotPersist(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->enable(0);
        } finally {
            $this->assertNull(($refs['getPersisted'])());
        }
    }

    public function testEnableRejectsRetentionDaysAboveRangeAndDoesNotPersist(): void
    {
        $refs = $this->makeService([
            'governed_mode' => 0,
            'credential_salt' => null,
            'metrics_retention_days' => 7,
        ]);
        $service = $refs['service'];

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->enable(3651);
        } finally {
            $this->assertNull(($refs['getPersisted'])());
        }
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
