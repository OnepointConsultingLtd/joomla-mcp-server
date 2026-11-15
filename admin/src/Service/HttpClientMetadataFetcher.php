<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Fetches Client ID Metadata Documents over HTTPS.
 *
 * Anyone can make the site fetch a URL of their choosing by using it as a
 * client_id, so this is guarded against server-side request forgery: https
 * on port 443 only, a host name rather than an IP literal, every address the
 * name resolves to public, the connection pinned to an address that was
 * checked (so DNS cannot change between check and connect), no redirects, a
 * short timeout and a small size cap.
 */
final class HttpClientMetadataFetcher implements OAuthClientMetadataFetcher
{
    private const MAX_BYTES = 16384;

    private const TIMEOUT = 5;

    /** @var callable(string $host): list<string> */
    private $resolver;

    private ClientInterface $http;

    /**
     * @param (callable(string $host): list<string>)|null $resolver Resolves a host to its IP addresses.
     */
    public function __construct(?callable $resolver = null, ?ClientInterface $http = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
        $this->http = $http ?? new Client();
    }

    /**
     * Why $url may not be fetched given the addresses its host resolved to,
     * or null when it may.
     *
     * @param list<string> $ips
     */
    public static function refusal(string $url, array $ips): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return 'only https URLs are fetched';
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return 'only the default https port is fetched';
        }

        if (filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) !== false) {
            return 'the client_id must use a host name, not an IP address';
        }

        if ($ips === []) {
            return 'the host name does not resolve';
        }

        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return 'the host name resolves to a non-public address';
            }
        }

        return null;
    }

    public function fetch(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = ($this->resolver)($host);

        $refusal = self::refusal($url, $ips);
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }

        $pinned = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => [$host . ':443:' . $pinned]],
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('the request failed');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('the server answered HTTP ' . $response->getStatusCode());
        }

        $body = $response->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('the document is larger than ' . self::MAX_BYTES . ' bytes');
        }

        $document = json_decode($body, true);
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('the document is not a JSON object');
        }

        return $document;
    }

    private static function isPublic(string $ip): bool
    {
        // An IPv4-mapped IPv6 address reaches the IPv4 host it embeds.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));
    }
}
