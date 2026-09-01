<?php
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Administrator\Service;
defined('_JEXEC') or die;
interface CredentialStoreInterface
{
    public function findBySelector(string $selector): ?CredentialRecord;
    public function touchLastUsed(int $credentialId): void;
}
