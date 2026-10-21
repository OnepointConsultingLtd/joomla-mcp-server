<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

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

    public function testEncodedFieldsUseTheBase64UrlAlphabet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $encrypted = $this->cipher()->encrypt(random_bytes(64));
            foreach (['ciphertext', 'nonce', 'tag'] as $field) {
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $encrypted[$field]);
            }
        }
    }

    public function testDecryptsPayloadsWrittenByTheBroken1_10_1Build(): void
    {
        // The 1.8.0-1.10.1 packages shipped encode() as str_replace('+/', '-_', base64_encode(...)).
        $legacy = static fn (string $encoded): string => rtrim(
            str_replace('+/', '-_', base64_encode((string) base64_decode(strtr($encoded, '-_', '+/')))),
            '='
        );

        for ($i = 0; $i < 50; $i++) {
            $plaintext = random_bytes(64);
            $encrypted = $this->cipher()->encrypt($plaintext);
            foreach (['ciphertext', 'nonce', 'tag'] as $field) {
                $encrypted[$field] = $legacy($encrypted[$field]);
            }
            $this->assertSame($plaintext, $this->cipher()->decrypt($encrypted));
        }
    }

    public function testInvalidKeyMaterialFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        new CredentialCipher('', 'invalid');
    }
}
