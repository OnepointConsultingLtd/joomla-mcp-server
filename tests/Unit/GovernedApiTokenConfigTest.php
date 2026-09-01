<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

final class GovernedApiTokenConfigTest extends TestCase
{
    /** @dataProvider configFiles */
    public function testLegacyTokenIsUnavailableWhenGovernedModeIsEnabled(string $path): void
    {
        $xml = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/<field\s+name="api_token"[^>]+showon="governed_mode:0"/',
            $xml
        );
        $this->assertMatchesRegularExpression(
            '/<field\s+name="governed_api_token_notice"[^>]+type="note"[^>]+showon="governed_mode:1"/',
            $xml
        );
    }

    /** @return iterable<string, array{string}> */
    public static function configFiles(): iterable
    {
        yield 'administrator options' => [__DIR__ . '/../../admin/config.xml'];
        yield 'install manifest options' => [__DIR__ . '/../../mcpserver.xml'];
    }
}
