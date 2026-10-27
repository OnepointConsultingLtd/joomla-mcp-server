<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCache;
use Joomla\Component\Mcpserver\Administrator\Service\PrincipalCache;
use Joomla\Component\Mcpserver\Tests\Stubs\StubCacheController;
use PHPUnit\Framework\TestCase;

final class JoomlaCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Factory::reset();
    }

    protected function tearDown(): void
    {
        Factory::reset();
    }

    public function testDeleteByPrefixInvalidatesMatchingEntries(): void
    {
        $cache = new CacheService(new JoomlaCache('com_mcpserver'));
        $cache->remember('article_associations:5', fn () => ['data' => []]);

        $cache->deleteByPrefix('article_associations:');

        $this->assertSame(
            ['data' => [['id' => 7]]],
            $cache->remember('article_associations:5', fn () => ['data' => [['id' => 7]]])
        );
        $this->assertSame(['com_mcpserver'], StubCacheController::$cleaned);
    }

    public function testDeleteByPrefixThroughPrincipalCacheReachesTheJoomlaGroup(): void
    {
        $principal = new AuthenticatedPrincipal(1, 'sel', 42, 'Client', 'api-token');
        $cache = new CacheService(new PrincipalCache(new JoomlaCache('com_mcpserver'), $principal));
        $cache->remember('articles_search:abc', fn () => 'stale');

        $cache->deleteByPrefix('articles_search:');

        $this->assertSame('fresh', $cache->remember('articles_search:abc', fn () => 'fresh'));
    }
}
