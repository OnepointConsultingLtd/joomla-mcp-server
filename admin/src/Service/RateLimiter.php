<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Registry\Registry;
use Psr\SimpleCache\CacheInterface;

class RateLimiter
{
    /** Joomla cache group that holds the request counters. */
    public const CACHE_GROUP = 'com_mcpserver_ratelimit';

    private CacheInterface $cache;
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(CacheInterface $cache, int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->cache = $cache;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * The one place that turns the component's rate_limit_* params into a
     * rate limiter. The DI provider, the RPC handler's no-container fallback
     * and the OAuth endpoints all build theirs here, so a new param only has
     * to be added once.
     *
     * @param CacheInterface|null $cache Where the counters are kept; the component's Joomla cache group by default.
     */
    public static function fromParams(Registry $params, ?CacheInterface $cache = null): self
    {
        return new self(
            $cache ?? new JoomlaCache(self::CACHE_GROUP),
            (int) $params->get('rate_limit_requests', 60),
            (int) $params->get('rate_limit_window', 60)
        );
    }

    public function checkLimit(string $identifier): ?array
    {
        $key = 'ratelimit:' . md5($identifier);
        $current = $this->cache->get($key, ['count' => 0, 'reset' => time() + $this->windowSeconds]);

        if (!is_array($current)) {
            $current = ['count' => 0, 'reset' => time() + $this->windowSeconds];
        }

        if ($current['reset'] < time()) {
            $current = ['count' => 0, 'reset' => time() + $this->windowSeconds];
        }

        $current['count']++;
        $this->cache->set($key, $current, $this->windowSeconds);

        if ($current['count'] > $this->maxRequests) {
            return [
                'limited' => true,
                'retry_after' => $current['reset'] - time(),
            ];
        }

        return null;
    }
}

