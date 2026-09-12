<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use Joomla\Component\Mcpserver\Administrator\Service\GovernanceKeyMaterial;
use PHPUnit\Framework\TestCase;

class GovernanceKeyMaterialTest extends TestCase
{
    private const SALT = 'component-salt-bytes-0123456789';

    public function testCreateCipherDerivesUsableCipherFromInjectedSecret(): void
    {
        $material = new GovernanceKeyMaterial(
            static fn (): string => 'joomla-site-secret-value',
            base64_encode(self::SALT)
        );

        $cipher = $material->createCipher();

        $this->assertInstanceOf(CredentialCipher::class, $cipher);
        $encrypted = $cipher->encrypt('api-token');
        $this->assertSame('api-token', $cipher->decrypt($encrypted));
    }

    public function testSecretProviderIsNotInvokedUntilCipherIsRequested(): void
    {
        $calls = 0;
        $material = new GovernanceKeyMaterial(
            static function () use (&$calls): string {
                $calls++;

                return 'joomla-site-secret-value';
            },
            base64_encode(self::SALT)
        );

        $this->assertSame(0, $calls);
        $material->createCipher();
        $this->assertSame(1, $calls);
    }

    public function testRejectsEmptySalt(): void
    {
        $this->expectException(\RuntimeException::class);
        new GovernanceKeyMaterial(static fn (): string => 'joomla-site-secret-value', '');
    }

    public function testRejectsMalformedBase64Salt(): void
    {
        $this->expectException(\RuntimeException::class);
        new GovernanceKeyMaterial(static fn (): string => 'joomla-site-secret-value', 'not valid base64!!');
    }

    public function testFailsClosedWhenInjectedSecretIsEmpty(): void
    {
        $material = new GovernanceKeyMaterial(static fn (): string => '', base64_encode(self::SALT));

        $this->expectException(\RuntimeException::class);
        $material->createCipher();
    }
}
