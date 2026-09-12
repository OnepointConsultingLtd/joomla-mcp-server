<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * Guards the numeric option fields against Joomla's `integer` form field, which
 * is not a number input at all: it renders a select list built from `first`,
 * `last` and `step`, ignores `min`/`max`, and returns an empty option list when
 * `step` is absent (IntegerField::getOptions() bails on step == 0). A field
 * declared `type="integer" min="1" max="3650"` therefore renders as an empty
 * dropdown that cannot be set at all, and its `default` never applies because
 * there is no option to select.
 *
 * Every numeric option here must be a `number` field with an explicit range.
 */
final class ConfigNumericFieldTest extends TestCase
{
    /**
     * Options the component reads as integers, each with the bounds the form
     * must advertise.
     *
     * @var array<string, array{min:string, max:string}>
     */
    private const NUMERIC_FIELDS = [
        'cache_ttl' => ['min' => '1', 'max' => '86400'],
        'tools_list_page_size' => ['min' => '1', 'max' => '500'],
        'rate_limit_requests' => ['min' => '1', 'max' => '10000'],
        'rate_limit_window' => ['min' => '1', 'max' => '86400'],
        'metrics_retention_days' => ['min' => '1', 'max' => '3650'],
    ];

    /**
     * @dataProvider configFiles
     */
    public function testNoOptionUsesTheIntegerFieldType(string $path): void
    {
        $fields = $this->fields($path);

        $integerFields = [];
        foreach ($fields as $name => $attributes) {
            if (($attributes['type'] ?? '') === 'integer') {
                $integerFields[] = $name;
            }
        }

        $this->assertSame(
            [],
            $integerFields,
            'Joomla\'s integer field is a first/last/step dropdown, empty unless stepped. Use type="number".'
        );
    }

    /**
     * @dataProvider configFiles
     */
    public function testNumericOptionsAreNumberFieldsWithAnExplicitRange(string $path): void
    {
        $fields = $this->fields($path);

        foreach (self::NUMERIC_FIELDS as $name => $range) {
            $this->assertArrayHasKey($name, $fields, "Option {$name} is missing from " . basename($path));

            $attributes = $fields[$name];
            $this->assertSame('number', $attributes['type'] ?? '', "Option {$name} must render as a number input.");
            $this->assertSame($range['min'], $attributes['min'] ?? '', "Option {$name} must advertise its minimum.");
            $this->assertSame($range['max'], $attributes['max'] ?? '', "Option {$name} must advertise its maximum.");
            $this->assertSame('1', $attributes['step'] ?? '', "Option {$name} must step in whole units.");
            $this->assertNotSame('', $attributes['default'] ?? '', "Option {$name} must carry a default.");
        }
    }

    /**
     * The administrator options form and the install manifest declare the same
     * fields twice; a fix applied to one and not the other leaves freshly
     * installed sites with the broken declaration.
     */
    public function testBothConfigFilesDeclareNumericOptionsIdentically(): void
    {
        $adminFields = $this->fields(__DIR__ . '/../../admin/config.xml');
        $manifestFields = $this->fields(__DIR__ . '/../../mcpserver.xml');

        foreach (array_keys(self::NUMERIC_FIELDS) as $name) {
            $this->assertSame(
                $adminFields[$name] ?? [],
                $manifestFields[$name] ?? [],
                "Option {$name} must be declared identically in admin/config.xml and mcpserver.xml."
            );
        }
    }

    /**
     * @return array<string, array<string, string>> Field attributes, keyed by field name.
     */
    private function fields(string $path): array
    {
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, "Unable to parse {$path}");

        $fields = [];
        foreach ($xml->xpath('//field') ?: [] as $field) {
            $attributes = [];
            foreach ($field->attributes() ?? [] as $key => $value) {
                $attributes[(string) $key] = (string) $value;
            }

            if (isset($attributes['name'])) {
                $fields[$attributes['name']] = $attributes;
            }
        }

        return $fields;
    }

    /** @return iterable<string, array{string}> */
    public static function configFiles(): iterable
    {
        yield 'administrator options' => [__DIR__ . '/../../admin/config.xml'];
        yield 'install manifest options' => [__DIR__ . '/../../mcpserver.xml'];
    }
}
