<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text administrators and users read uses GLOSSARY.md's terms.
 * Shared-token mode is a supported mode, not a "legacy" one, and someone
 * granted a permission is not its "holder".
 */
final class LanguageGlossaryTest extends TestCase
{
    private const ADMIN_LANGUAGE_FILE = 'admin/language/en-GB/en-GB.com_mcpserver.ini';

    private const LANGUAGE_FILES = [
        self::ADMIN_LANGUAGE_FILE,
        'admin/language/en-GB/en-GB.com_mcpserver.sys.ini',
        'site/language/en-GB/com_mcpserver.ini',
    ];

    /**
     * @return array<string,string>
     */
    private static function strings(string $file): array
    {
        $strings = parse_ini_file(__DIR__ . '/../../' . $file);
        self::assertIsArray($strings, $file . ' must parse');

        return $strings;
    }

    /**
     * @return array<string,array{string}>
     */
    public static function languageFiles(): array
    {
        return array_combine(self::LANGUAGE_FILES, array_map(static fn (string $file): array => [$file], self::LANGUAGE_FILES));
    }

    #[DataProvider('languageFiles')]
    public function testNoStringUsesAWordTheGlossaryAvoids(string $file): void
    {
        foreach (self::strings($file) as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\blegacy\b/i', $text, "{$file}: {$key} says \"legacy\"; the glossary term is shared-token mode");
            $this->assertDoesNotMatchRegularExpression('/\bholders?\b/i', $text, "{$file}: {$key} says \"holder\"; say who was granted the permission");
        }
    }

    public function testTheApiTokenOptionIsNamedAsInTheGlossary(): void
    {
        $this->assertSame('Shared Joomla API Token', self::strings(self::ADMIN_LANGUAGE_FILE)['COM_MCPSERVER_CONFIG_API_TOKEN']);
    }
}
