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

class ToolRegistryTest extends TestCase
{
    public function testEveryAnnotationTitleBecomesTheTopLevelTitle(): void
    {
        foreach ((new ToolRegistry())->getAll() as $tool) {
            if (isset($tool['annotations']['title'])) {
                $this->assertSame($tool['annotations']['title'], $tool['title'] ?? null, $tool['name']);
            }
        }
    }

    public function testAnExplicitTitleIsKept(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'custom',
            'title' => 'Own title',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['title' => 'Annotation title'],
        ]);

        $this->assertSame('Own title', $registry->get('custom')['title']);
    }

    public function testTargetIdsAreAnnotatedOnFortyEightTools(): void
    {
        $annotated = array_filter(
            (new ToolRegistry())->getAll(),
            static fn (array $tool): bool => (new ToolRegistry())->paramHeaders($tool['name']) !== []
        );

        $this->assertCount(48, $annotated);
    }

    public function testOnlyTopLevelIntegersAreAnnotatedWithValidUniqueNames(): void
    {
        $registry = new ToolRegistry();
        foreach ($registry->getAll() as $tool) {
            $headers = $registry->paramHeaders($tool['name']);
            foreach ($headers as $property => $header) {
                $this->assertSame('integer', $tool['inputSchema']['properties'][$property]['type'], "{$tool['name']}.{$property}");
                $this->assertMatchesRegularExpression('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $header);
            }
            $lower = array_map('strtolower', array_values($headers));
            $this->assertSame($lower, array_values(array_unique($lower)), "{$tool['name']}: header names must be unique");
        }
    }

    public function testParamHeadersForAKnownTool(): void
    {
        $this->assertSame(['id' => 'Id'], (new ToolRegistry())->paramHeaders('get_article_by_id'));
        $this->assertSame([], (new ToolRegistry())->paramHeaders('no_such_tool'));
    }

    public function testANonIntegerTargetIsNotAnnotated(): void
    {
        $registry = new ToolRegistry();
        $registry->register([
            'name' => 'by_slug',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ]);
        $registry->register(['name' => 'no_params', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]);

        $this->assertSame([], $registry->paramHeaders('by_slug'));
        $this->assertSame([], $registry->paramHeaders('no_params'));
    }
}
