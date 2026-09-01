<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Namespaces cache keys by the authenticated governed principal so distinct
 * governed users never observe each other's cached RPC results or SSE
 * responses. A null principal (legacy shared-token mode) is a transparent
 * pass-through: keys are returned unmodified, preserving legacy behavior.
 */
final class PrincipalCache implements CacheInterface
{
    private CacheInterface $inner;
    private ?AuthenticatedPrincipal $principal;

    public function __construct(CacheInterface $inner, ?AuthenticatedPrincipal $principal)
    {
        $this->inner = $inner;
        $this->principal = $principal;
    }

    /**
     * Namespace a raw request-local cache key (e.g. RpcService's per-resource
     * keys) by the authenticated user id. Deliberately keyed on the Joomla
     * user id rather than the credential id/selector or API token, so a
     * governed user's cached results stay scoped to that user regardless of
     * which credential they authenticated with.
     */
    public static function keyFor(string $key, ?AuthenticatedPrincipal $principal): string
    {
        return $principal === null ? $key : 'u' . $principal->userId . ':' . $key;
    }

    /**
     * Bind an SSE session's cache key to the authenticated principal's
     * credential id. The client-visible sessionId stays a single random
     * token, but the poster's and the poller's principal must independently
     * derive the same storage key for a response to be delivered — so a
     * session opened or posted to by one governed credential cannot be
     * consumed by another. Legacy (non-governed) sessions keep their raw
     * sessionId, preserving existing behavior.
     */
    public static function sessionKeyFor(string $sessionId, ?AuthenticatedPrincipal $principal): string
    {
        return $principal === null ? $sessionId : 'c' . $principal->credentialId . ':' . $sessionId;
    }

    private function namespaceKey(string $key): string
    {
        return self::keyFor($key, $this->principal);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->namespaceKey($key), $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->inner->set($this->namespaceKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->namespaceKey($key));
    }

    public function clear(): bool
    {
        return $this->inner->clear();
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
        return $this->inner->has($this->namespaceKey($key));
    }

    /**
     * Forwarded so CacheService::deleteByPrefix (which detects this method
     * via method_exists) keeps prefix-based invalidation scoped to the
     * current principal instead of clearing every user's cache entries.
     */
    public function deleteByPrefix(string $prefix): void
    {
        if (method_exists($this->inner, 'deleteByPrefix')) {
            $this->inner->deleteByPrefix($this->namespaceKey($prefix));
        }
    }
}
