<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CompletionService;
use PHPUnit\Framework\TestCase;

class CompletionServiceTest extends TestCase
{
    public function testPrefixMatchesComeBeforeSubstringMatches(): void
    {
        $result = CompletionService::complete(['Kitchen Garden', 'Garden', 'News', 'Gardening tips'], 'gar');

        $this->assertSame(['values' => ['Garden', 'Gardening tips', 'Kitchen Garden'], 'total' => 3, 'hasMore' => false], $result);
    }

    public function testMatchingIgnoresCase(): void
    {
        $this->assertSame(['fr-FR'], CompletionService::complete(['fr-FR', 'de-DE'], 'FR')['values']);
    }

    public function testEmptyValueListsEverything(): void
    {
        $this->assertSame(['b', 'a'], CompletionService::complete(['b', 'a'], '')['values']);
    }

    public function testResultsAreCappedAtOneHundredWithTotalAndHasMore(): void
    {
        $candidates = array_map(static fn (int $n): string => 'item ' . $n, range(1, 150));

        $result = CompletionService::complete($candidates, 'item');

        $this->assertCount(100, $result['values']);
        $this->assertSame(150, $result['total']);
        $this->assertTrue($result['hasMore']);
    }

    public function testDuplicatesAndEmptyCandidatesAreDropped(): void
    {
        $this->assertSame(['x'], CompletionService::complete(['x', '', 'x'], '')['values']);
    }

    public function testCaseVariantsCollapseToTheFirstSpelling(): void
    {
        $this->assertSame(['News'], CompletionService::complete(['News', 'news', 'NEWS'], '')['values']);
    }

    public function testWildcardCharactersMatchLiterally(): void
    {
        $this->assertSame(['100% organic'], CompletionService::complete(['100% organic', '100 percent'], '100%')['values']);
        $this->assertSame([], CompletionService::complete(['abc'], 'a_c')['values']);
    }

    public function testNoMatchIsAnEmptyCompletion(): void
    {
        $this->assertSame(['values' => [], 'total' => 0, 'hasMore' => false], CompletionService::complete(['a'], 'zzz'));
    }
}
