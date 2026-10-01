<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * MCP interprets a schema without `$schema` as JSON Schema 2020-12, but tool
 * arguments are validated by justinrainbow/json-schema 5.3, which implements
 * draft-04. The two agree only on a common subset of keywords — draft-04's
 * boolean exclusiveMinimum, array-form items and `definitions` all mean
 * something else (or nothing) in 2020-12 — so every inputSchema must stay
 * inside that subset for what clients read and what the server enforces to be
 * the same contract.
 */
class ToolSchemaDialectTest extends TestCase
{
    /** Keywords whose meaning is identical in draft-04 and 2020-12. */
    private const PORTABLE_KEYWORDS = [
        'type', 'properties', 'required', 'description', 'title', 'enum', 'default',
        'additionalProperties', 'items', 'minimum', 'maximum', 'minLength', 'maxLength',
        'pattern', 'minItems', 'maxItems', 'uniqueItems', 'format',
        // An annotation (Mcp-Param-* headers) that validators ignore.
        'x-mcp-header',
    ];

    public function testEveryToolInputSchemaIsAnObjectSchema(): void
    {
        foreach ((new ToolRegistry())->getAll() as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'] ?? null, "{$tool['name']}: inputSchema root must be type object");
        }
    }

    public function testEveryToolInputSchemaUsesOnlyPortableKeywords(): void
    {
        foreach ((new ToolRegistry())->getAll() as $tool) {
            $this->assertSame([], $this->nonPortableKeywords($tool['inputSchema']), "{$tool['name']}: non-portable JSON Schema keywords");
        }
    }

    public function testTheCheckFlagsKeywordsThatChangedMeaning(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true],
                'pair' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]],
                'ref' => ['$ref' => '#/$defs/thing'],
                'empty' => ['type' => 'object', 'required' => []],
            ],
            '$defs' => ['thing' => ['type' => 'string']],
        ];

        $this->assertSame([
            'properties.count.exclusiveMinimum',
            'properties.pair.items (array form)',
            'properties.ref.$ref',
            'properties.empty.required (must be a non-empty list in draft-04)',
            '$defs',
        ], $this->nonPortableKeywords($schema));
    }

    /**
     * @param  array<string, mixed>|object  $schema
     * @return list<string>
     */
    private function nonPortableKeywords(array|object $schema, string $path = ''): array
    {
        $found = [];
        foreach ((array) $schema as $keyword => $value) {
            $at = $path === '' ? (string) $keyword : $path . '.' . $keyword;

            if (!in_array($keyword, self::PORTABLE_KEYWORDS, true)) {
                $found[] = $at;
                continue;
            }

            if ($keyword === 'properties') {
                foreach ((array) $value as $name => $subSchema) {
                    $found = [...$found, ...$this->nonPortableKeywords($subSchema, $at . '.' . $name)];
                }
            } elseif ($keyword === 'items') {
                if (is_array($value) && array_is_list($value)) {
                    $found[] = $at . ' (array form)';
                } else {
                    $found = [...$found, ...$this->nonPortableKeywords($value, $at)];
                }
            } elseif ($keyword === 'additionalProperties' && !is_bool($value)) {
                $found = [...$found, ...$this->nonPortableKeywords($value, $at)];
            } elseif ($keyword === 'required' && (!is_array($value) || $value === [])) {
                $found[] = $at . ' (must be a non-empty list in draft-04)';
            }
        }

        return $found;
    }
}
