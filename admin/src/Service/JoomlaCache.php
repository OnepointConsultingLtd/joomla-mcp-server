<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Psr\SimpleCache\CacheInterface;
use DateInterval;

class JoomlaCache implements CacheInterface
{
    private $cache;
    private string $group;

    public function __construct(string $group = 'com_mcpserver')
    {
        $this->group = $group;
        $this->cache = Factory::getCache($group, '');

        // Joomla ships with global caching OFF, and Cache::store() silently
        // no-ops when it is. The rate limiter and the SSE response relay depend
        // on this store actually persisting (a no-op disables rate limiting and
        // leaves SSE clients hanging), so force caching on for this instance.
        // Joomla honours the per-instance flag over the global setting.
        $this->cache->setCaching(true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->cache->get($key);
        return $data !== false ? $data : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if ($ttl !== null) {
            $seconds = $ttl instanceof DateInterval
                ? (int) ((new \DateTimeImmutable('now'))->add($ttl)->getTimestamp() - time())
                : $ttl;
            if ($seconds > 0) {
                // PSR-16 expresses TTL in seconds, but Joomla's setLifeTime() expects
                // minutes (CacheStorage multiplies the value by 60). Convert so callers'
                // second-based TTLs are honoured instead of being applied 60x too long.
                // Round up, with a 1-minute floor (Joomla's file storage cannot express
                // sub-minute expiry; TTL-honouring backends get ~the requested window).
                $minutes = (int) max(1, (int) ceil($seconds / 60));
                $this->cache->setLifeTime($minutes);
            }
        }

        return $this->cache->store($value, $key);
    }

    public function delete(string $key): bool
    {
        return $this->cache->remove($key);
    }

    public function clear(): bool
    {
        return $this->cache->clean($this->group);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get((string) $key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Garbage-collect expired entries across the cache store.
     *
     * Uses the instance's default lifetime (the global Joomla cachetime). Do NOT
     * call this after set()/setLifeTime() on the same instance: Joomla's file
     * storage gc() recurses the entire cache base and would purge other groups'
     * entries using whatever (possibly shortened) lifetime is in effect.
     */
    public function gc(): bool
    {
        return (bool) $this->cache->gc();
    }

    /**
     * Joomla's cache cannot delete by key prefix: storages persist entries under
     * md5-hashed ids, and Cache::getAll() returns per-group stats keyed by group
     * name, never entry keys. Matching a prefix against getAll() therefore never
     * removed anything, leaving every prefix-invalidated read stale until its TTL
     * ran out (e.g. set_article_associations echoing the pre-write state).
     *
     * Clean the whole group instead. It over-invalidates, but the group holds
     * only this component's read cache, so the cost is a few extra cache misses.
     */
    public function deleteByPrefix(string $prefix): void
    {
        $this->clear();
    }
}


