<?php
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Tests\Unit;
defined('_JEXEC') or die;
use DateTimeImmutable;
use Joomla\Component\Mcpserver\Administrator\Service\{CredentialCipher,CredentialRecord,CredentialStoreInterface,GovernedCredentialAuthenticator,McpCredential};
use PHPUnit\Framework\TestCase;
final class GovernanceTestStore implements CredentialStoreInterface { public ?CredentialRecord $record = null; public int $touches = 0; public function findBySelector(string $s): ?CredentialRecord { return $this->record?->selector === $s ? $this->record : null; } public function touchLastUsed(int $id): void { ++$this->touches; } }
final class GovernedCredentialAuthenticatorTest extends TestCase
{
    public function testOnlyActiveUnexpiredCredentialProducesPrincipal(): void
    {
        $issued=McpCredential::issue(); $cipher=new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $store=new GovernanceTestStore(); $store->record=new CredentialRecord(1,$issued['selector'],2,'User',$issued['verifier'],$cipher->encrypt('api-token'),'active');
        $principal=(new GovernedCredentialAuthenticator($store,$cipher))->authenticateBearer('Bearer '.$issued['token'],new DateTimeImmutable());
        $this->assertSame(2,$principal->userId); $this->assertSame('api-token',$principal->joomlaApiToken); $this->assertSame(1,$store->touches);
    }
    public function testUnknownCredentialFailsWithoutTouchingStore(): void
    {
        $issued=McpCredential::issue(); $store=new GovernanceTestStore(); $cipher=new CredentialCipher('site-secret', base64_encode('component-salt-0123456789'));
        $this->expectException(\RuntimeException::class); (new GovernedCredentialAuthenticator($store,$cipher))->authenticateBearer('Bearer '.$issued['token'],new DateTimeImmutable());
    }
}
