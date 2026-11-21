<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\RateLimiter;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * RateLimiter::fromParams() is the single builder the DI provider, the RPC
 * handler's fallback and the OAuth endpoints share.
 */
final class RateLimiterTest extends TestCase
{
    public function testFromParamsUsesTheConfiguredRequestLimit(): void
    {
        $limiter = RateLimiter::fromParams(new Registry(['rate_limit_requests' => 2, 'rate_limit_window' => 60]), new SimpleArrayCache());

        $this->assertNull($limiter->checkLimit('client'));
        $this->assertNull($limiter->checkLimit('client'));
        $this->assertTrue($limiter->checkLimit('client')['limited'] ?? false);
    }

    public function testFromParamsUsesTheConfiguredWindow(): void
    {
        $limiter = RateLimiter::fromParams(new Registry(['rate_limit_requests' => 1, 'rate_limit_window' => 900]), new SimpleArrayCache());

        $limiter->checkLimit('client');
        $retryAfter = $limiter->checkLimit('client')['retry_after'] ?? 0;

        $this->assertGreaterThan(60, $retryAfter);
        $this->assertLessThanOrEqual(900, $retryAfter);
    }

    public function testFromParamsDefaultsToSixtyRequestsPerMinuteWhenOptionsWereNeverSaved(): void
    {
        $limiter = RateLimiter::fromParams(new Registry(), new SimpleArrayCache());

        for ($i = 0; $i < 60; $i++) {
            $this->assertNull($limiter->checkLimit('client'));
        }
        $limited = $limiter->checkLimit('client');

        $this->assertTrue($limited['limited'] ?? false);
        $this->assertLessThanOrEqual(60, $limited['retry_after']);
    }
}
