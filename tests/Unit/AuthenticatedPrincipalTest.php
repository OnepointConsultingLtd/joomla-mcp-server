<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use PHPUnit\Framework\TestCase;

/**
 * The principal carries a decrypted Joomla API token into the audit service,
 * the action logger, the authorizer and the cache. These tests pin the
 * containment guarantees that keep it from reaching a log file.
 */
final class AuthenticatedPrincipalTest extends TestCase
{
    private const TOKEN = 'super-secret-joomla-api-token';

    private function principal(): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(7, 'selector16charsx', 42, 'Ada', self::TOKEN);
    }

    public function testApiTokenIsReachableOnlyThroughTheAccessor(): void
    {
        $this->assertSame(self::TOKEN, $this->principal()->apiToken());
        $this->assertFalse(
            property_exists($this->principal(), 'joomlaApiToken')
                && (new \ReflectionProperty(AuthenticatedPrincipal::class, 'joomlaApiToken'))->isPublic(),
            'the token property must not be public'
        );
    }

    public function testJsonEncodeDoesNotExposeTheToken(): void
    {
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($this->principal()));
    }

    public function testPrintRDoesNotExposeTheToken(): void
    {
        $dumped = print_r($this->principal(), true);

        $this->assertStringNotContainsString(self::TOKEN, $dumped);
        $this->assertStringContainsString('redacted', $dumped);
    }

    public function testVarDumpIsRedacted(): void
    {
        ob_start();
        var_dump($this->principal());
        $dumped = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::TOKEN, $dumped);
        $this->assertStringContainsString('redacted', $dumped);
    }

    public function testSerializationIsRefused(): void
    {
        // Serializing would put the plaintext token into the session store.
        $this->expectException(\LogicException::class);
        serialize($this->principal());
    }

    /**
     * @dataProvider illegalConstructions
     */
    public function testIllegalPrincipalsAreRejected(int $credentialId, string $selector, int $userId, string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthenticatedPrincipal($credentialId, $selector, $userId, 'Ada', $token);
    }

    /**
     * @return array<string, array{int, string, int, string}>
     */
    public static function illegalConstructions(): array
    {
        return [
            'no credential id' => [0, 'selector16charsx', 42, self::TOKEN],
            'no selector' => [7, '', 42, self::TOKEN],
            'no user id' => [7, 'selector16charsx', 0, self::TOKEN],
            'empty token' => [7, 'selector16charsx', 42, ''],
        ];
    }
}
