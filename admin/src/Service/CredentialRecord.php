<?php
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Administrator\Service;
defined('_JEXEC') or die;
use DateTimeImmutable;
final class CredentialRecord
{
    public function __construct(
        public readonly int $id, public readonly string $selector, public readonly int $userId,
        public readonly string $name, public readonly string $verifier, public readonly array $encryptedToken,
        public readonly string $status, public readonly ?DateTimeImmutable $expires = null,
        public readonly ?DateTimeImmutable $revoked = null,
    ) {}
}
