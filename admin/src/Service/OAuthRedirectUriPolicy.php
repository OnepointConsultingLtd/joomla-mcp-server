<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Which redirect URIs a client may register, and when a requested one matches.
 *
 * Registration is open (anyone can call the registration endpoint), so this is
 * what stops an authorisation code being delivered somewhere it can be stolen:
 *
 * - https URIs, optionally limited to an administrator's allow list;
 * - http only on a loopback address, for native apps (RFC 8252 §7.3);
 * - private-use schemes such as `cursor://` (RFC 8252 §7.1), except schemes
 *   that execute or read locally (javascript:, data:, file:, ...) and those
 *   that just reopen a URL in a mobile browser (x-safari-https:, ...), which
 *   would carry the code to any website. When an allow list is set, a
 *   private-use scheme must be on it too, written as "scheme:".
 *
 * No fragments (RFC 6749 §3.1.2).
 */
final class OAuthRedirectUriPolicy
{
    private const MAX_LENGTH = 2000;

    private const BLOCKED_SCHEMES = [
        'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'filesystem', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms',
        // Browser launchers: each opens an arbitrary web URL.
        'x-safari-http', 'x-safari-https', 'googlechrome', 'googlechromes', 'microsoft-edge', 'microsoft-edge-http',
        'microsoft-edge-https', 'firefox', 'opera-http', 'opera-https', 'brave', 'intent', 'android-app', 'x-web-search',
    ];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param list<string> $allowed Lower-case https hosts ("*.example.com" matches any subdomain)
     *                            and private-use schemes ("cursor:"). Empty allows any.
     */
    public function __construct(private readonly array $allowed = [])
    {
    }

    /**
     * @return string|null Why the URI is refused, or null if it is acceptable.
     */
    public function refusal(string $uri): ?string
    {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            return 'redirect_uri must be a non-empty URI without whitespace';
        }

        if (str_contains($uri, '#')) {
            return 'redirect_uri must not contain a fragment';
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'])) {
            return 'redirect_uri must be an absolute URI';
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        if ($scheme === 'https') {
            if ($host === '') {
                return 'redirect_uri must name a host';
            }

            return $this->httpsHostAllowed($host) ? null : 'redirect_uri host ' . $host . ' is not in this site\'s allowed redirects';
        }

        if ($scheme === 'http') {
            return in_array($host, self::LOOPBACK_HOSTS, true) ? null : 'http redirect_uri is only allowed for loopback addresses; use https';
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1 || in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return 'redirect_uri scheme ' . $scheme . ' is not allowed';
        }

        if ($this->allowed !== [] && !in_array($scheme . ':', $this->allowed, true)) {
            return 'redirect_uri scheme ' . $scheme . ' is not in this site\'s allowed redirects';
        }

        return null;
    }

    /**
     * Exact string match (OAuth 2.1 §4.1.1), except that a loopback http URI
     * matches on any port: native apps bind an ephemeral port per attempt
     * (RFC 8252 §7.3).
     */
    public static function matches(string $registered, string $requested): bool
    {
        if (hash_equals($registered, $requested)) {
            return true;
        }

        $a = parse_url($registered);
        $b = parse_url($requested);
        if ($a === false || $b === false) {
            return false;
        }

        $isLoopback = static fn (array $p): bool => strtolower($p['scheme'] ?? '') === 'http'
            && in_array(strtolower($p['host'] ?? ''), self::LOOPBACK_HOSTS, true);

        if (!$isLoopback($a) || !$isLoopback($b)) {
            return false;
        }

        $withoutPort = static function (array $p): array {
            unset($p['port']);
            $p['scheme'] = strtolower($p['scheme']);
            $p['host'] = strtolower($p['host']);

            return $p;
        };

        return $withoutPort($a) === $withoutPort($b);
    }

    private function httpsHostAllowed(string $host): bool
    {
        if ($this->allowed === []) {
            return true;
        }

        foreach ($this->allowed as $allowed) {
            if ($allowed === $host) {
                return true;
            }
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }
}
