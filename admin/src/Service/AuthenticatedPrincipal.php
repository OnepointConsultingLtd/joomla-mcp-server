<?php
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Administrator\Service;
defined('_JEXEC') or die;
final class AuthenticatedPrincipal
{
    public function __construct(
        public readonly int $credentialId,
        public readonly string $selector,
        public readonly int $userId,
        public readonly string $credentialName,
        public readonly string $joomlaApiToken,
    ) {}
}
