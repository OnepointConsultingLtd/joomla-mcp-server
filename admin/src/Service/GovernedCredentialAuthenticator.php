<?php
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Administrator\Service;
defined('_JEXEC') or die;
use DateTimeImmutable;
final class GovernedCredentialAuthenticator
{
    private const FAILURE = 'Invalid or expired MCP credential';
    private const DUMMY_VERIFIER = '$2y$10$45dIGQIfZGND4Y24Zc5vDOR4kIxZijEC6b.Z5fHTiozeGWFAafijm';
    public function __construct(private CredentialStoreInterface $store, private CredentialCipher $cipher) {}
    public function authenticateBearer(string $header, callable|DateTimeImmutable $now): AuthenticatedPrincipal
    {
        $parsed = McpCredential::parseBearer($header);
        $record = $parsed === null ? null : $this->store->findBySelector($parsed['selector']);
        $validSecret = McpCredential::verify($parsed['secret'] ?? '', $record?->verifier ?? self::DUMMY_VERIFIER);
        $time = $now instanceof DateTimeImmutable ? $now : $now();
        if ($record === null || $record->status !== 'active' || $record->revoked !== null || ($record->expires !== null && $record->expires <= $time) || !$validSecret) throw new \RuntimeException(self::FAILURE);
        try { $token = $this->cipher->decrypt($record->encryptedToken); } catch (\RuntimeException) { throw new \RuntimeException(self::FAILURE); }
        if ($token === '') throw new \RuntimeException(self::FAILURE);
        $this->store->touchLastUsed($record->id);
        return new AuthenticatedPrincipal($record->id, $record->selector, $record->userId, $record->name, $token);
    }
}
