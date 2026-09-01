<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;

class CredentialCipherTest extends TestCase
{
    private const SECRET = 'joomla-site-secret-value';

    private function cipher(string $secret = self::SECRET): CredentialCipher
    {
        return new CredentialCipher($secret, base64_encode('component-salt-bytes-0123456789'));
    }

    public function testRoundTripEncryptsWithoutPlaintext(): void
    {
        $encrypted = $this->cipher()->encrypt('api-token');
        $this->assertSame('api-token', $this->cipher()->decrypt($encrypted));
        $this->assertNotSame('api-token', $encrypted['ciphertext']);
        $this->assertSame(1, $encrypted['key_version']);
    }

    public function testTamperingAndWrongKeyFailClosed(): void
    {
        $encrypted = $this->cipher()->encrypt('api-token');
        $encrypted['tag'] = 'AA' . substr($encrypted['tag'], 2);
        $this->expectException(\RuntimeException::class);
        $this->cipher('other-secret')->decrypt($encrypted);
    }

    public function testInvalidKeyMaterialFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        new CredentialCipher('', 'invalid');
    }
}
