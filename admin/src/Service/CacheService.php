<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Psr\SimpleCache\CacheInterface;

class CacheService
{
    private CacheInterface $cache;
    private int $defaultTtlSeconds;

    public function __construct(CacheInterface $cache, int $defaultTtlSeconds = 60)
    {
        $this->cache = $cache;
        $this->defaultTtlSeconds = $defaultTtlSeconds;
    }

    private const SENTINEL = "\x00__CACHE_MISS__\x00";

    /**
     * How long a read may be served from this cache, in seconds — and therefore
     * how long a client may treat it as fresh.
     */
    public function getDefaultTtl(): int
    {
        return max(0, $this->defaultTtlSeconds);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $cached = $this->cache->get($key, self::SENTINEL);
        if ($cached !== self::SENTINEL) {
            return $cached;
        }

        $value = $callback();
        $this->cache->set($key, $value, $ttl ?? $this->defaultTtlSeconds);
        return $value;
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }

    public function deleteByPrefix(string $prefix): void
    {
        if (method_exists($this->cache, 'deleteByPrefix')) {
            $this->cache->deleteByPrefix($prefix);
        }
    }
}


