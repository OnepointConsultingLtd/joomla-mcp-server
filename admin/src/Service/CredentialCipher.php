<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

final class CredentialCipher
{
    private const CIPHER_METHOD = 'aes-256-gcm';
    private const KEY_LENGTH = 32;
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const HKDF_INFO = 'com_mcpserver:credential-cipher:v1';
    private const CURRENT_KEY_VERSION = 1;

    private string $key;

    public function __construct(string $siteSecret, string $componentSalt)
    {
        if (!extension_loaded('openssl') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required for credential encryption');
        }
        if ($siteSecret === '' || $componentSalt === '') {
            throw new \RuntimeException('Credential encryption key material must not be empty');
        }
        $salt = base64_decode($componentSalt, true);
        if ($salt === false || $salt === '') {
            throw new \RuntimeException('Component salt must be valid base64');
        }
        $key = hash_hkdf('sha256', $siteSecret, self::KEY_LENGTH, self::HKDF_INFO, $salt);
        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw new \RuntimeException('Unable to derive credential encryption key');
        }
        $this->key = $key;
    }

    /** @return array{ciphertext:string,nonce:string,tag:string,key_version:int} */
    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER_METHOD, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Unable to encrypt credential');
        }
        return ['ciphertext' => self::encode($ciphertext), 'nonce' => self::encode($nonce), 'tag' => self::encode($tag), 'key_version' => self::CURRENT_KEY_VERSION];
    }

    /** @param array{ciphertext?:mixed,nonce?:mixed,tag?:mixed,key_version?:mixed} $encrypted */
    public function decrypt(array $encrypted): string
    {
        foreach (['ciphertext', 'nonce', 'tag', 'key_version'] as $field) {
            if (!array_key_exists($field, $encrypted)) {
                throw new \RuntimeException("Encrypted payload is missing '{$field}'");
            }
        }
        if ($encrypted['key_version'] !== self::CURRENT_KEY_VERSION || !is_string($encrypted['ciphertext']) || !is_string($encrypted['nonce']) || !is_string($encrypted['tag'])) {
            throw new \RuntimeException('Malformed credential encryption payload');
        }
        $nonce = self::decode($encrypted['nonce']);
        $tag = self::decode($encrypted['tag']);
        if (strlen($nonce) !== self::NONCE_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Malformed credential encryption payload');
        }
        $plaintext = openssl_decrypt(self::decode($encrypted['ciphertext']), self::CIPHER_METHOD, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt credential');
        }
        return $plaintext;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $value)) {
            throw new \RuntimeException('Malformed base64url encoding');
        }
        $value = strtr($value, '-_', '+/') . str_repeat('=', (4 - (strlen($value) % 4)) % 4);
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \RuntimeException('Malformed base64url encoding');
        }
        return $decoded;
    }
}
