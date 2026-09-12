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
use Joomla\Component\Mcpserver\Administrator\Service\PrincipalCache;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use PHPUnit\Framework\TestCase;

final class PrincipalCacheTest extends TestCase
{
    private function principal(int $credentialId, int $userId, string $selector = 'sel'): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal($credentialId, $selector, $userId, 'Client', 'api-token');
    }

    public function testGetSetIsolatesEntriesBetweenDifferentPrincipals(): void
    {
        $inner = new SimpleArrayCache();
        $alice = new PrincipalCache($inner, $this->principal(1, 10));
        $bob   = new PrincipalCache($inner, $this->principal(2, 20));

        $alice->set('article:1', 'alice-data');
        $bob->set('article:1', 'bob-data');

        self::assertSame('alice-data', $alice->get('article:1'));
        self::assertSame('bob-data', $bob->get('article:1'));
    }

    public function testDeleteByOnePrincipalDoesNotAffectAnotherPrincipalsEntry(): void
    {
        $inner = new SimpleArrayCache();
        $alice = new PrincipalCache($inner, $this->principal(1, 10));
        $bob   = new PrincipalCache($inner, $this->principal(2, 20));

        $alice->set('article:1', 'alice-data');
        $bob->set('article:1', 'bob-data');

        $alice->delete('article:1');

        self::assertNull($alice->get('article:1'));
        self::assertSame('bob-data', $bob->get('article:1'));
    }

    public function testDeleteByPrefixIsScopedToPrincipal(): void
    {
        $inner = new SimpleArrayCache();
        $alice = new PrincipalCache($inner, $this->principal(1, 10));
        $bob   = new PrincipalCache($inner, $this->principal(2, 20));

        $alice->set('articles_search:foo', 'alice-data');
        $bob->set('articles_search:foo', 'bob-data');

        $alice->deleteByPrefix('articles_search:');

        self::assertNull($alice->get('articles_search:foo'));
        self::assertSame('bob-data', $bob->get('articles_search:foo'));
    }

    public function testNullPrincipalIsTransparentPassThroughToLegacyKeys(): void
    {
        $inner = new SimpleArrayCache();
        $legacy = new PrincipalCache($inner, null);

        $legacy->set('article:1', 'shared-data');

        self::assertSame('shared-data', $inner->get('article:1'));
        self::assertSame('shared-data', $legacy->get('article:1'));
    }

    public function testKeyForNamespacesByUserIdNotCredentialOrSelector(): void
    {
        $principalA = $this->principal(1, 42, 'selector-a');
        $principalB = $this->principal(2, 42, 'selector-b');

        self::assertSame(
            PrincipalCache::keyFor('article:1', $principalA),
            PrincipalCache::keyFor('article:1', $principalB)
        );
        self::assertSame('u42:article:1', PrincipalCache::keyFor('article:1', $principalA));
    }

    public function testKeyForReturnsLegacyKeyForNullPrincipal(): void
    {
        self::assertSame('article:1', PrincipalCache::keyFor('article:1', null));
    }

    public function testSessionKeyForBindsToCredentialIdAndDiffersAcrossCredentials(): void
    {
        $sessionId = 'abc123';
        $principalA = $this->principal(1, 42);
        $principalB = $this->principal(2, 42);

        $keyA = PrincipalCache::sessionKeyFor($sessionId, $principalA);
        $keyB = PrincipalCache::sessionKeyFor($sessionId, $principalB);

        self::assertNotSame($keyA, $keyB);
        self::assertSame($keyA, PrincipalCache::sessionKeyFor($sessionId, $principalA));
    }

    public function testSessionKeyForReturnsLegacySessionIdForNullPrincipal(): void
    {
        self::assertSame('abc123', PrincipalCache::sessionKeyFor('abc123', null));
    }
}
