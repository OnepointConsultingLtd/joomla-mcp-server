<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

class RestClient
{
    public const RENDERED_PAGE_MAX_BYTES = 524288;

    public const RENDERED_PAGE_TIMEOUT = 30.0;

    private GuzzleClient $http;
    private string $baseUrl;
    private ?string $apiToken;
    private ?string $resolveIp;
    private LoggerInterface $logger;

    public function __construct(string $baseUrl, ?string $apiToken, LoggerInterface $logger, bool $verifySsl = true, ?string $resolveIp = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $apiToken = $apiToken !== null ? trim($apiToken) : null;
        $this->apiToken = $apiToken !== '' ? $apiToken : null;
        $this->logger = $logger;
        $this->resolveIp = $resolveIp !== null && trim($resolveIp) !== '' ? trim($resolveIp) : null;

        $config = [
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 15.0,
            'verify' => $verifySsl,
        ];

        // Pin the base host to a specific IP (e.g. 127.0.0.1) for this client only,
        // so the box can reach its own public hostname without NAT hairpinning while
        // still sending the correct Host header and validating TLS against the real name.
        $resolveEntry = $this->resolveIp !== null ? $this->buildResolveEntry($this->resolveIp) : null;
        if ($resolveEntry !== null) {
            $config['curl'] = [\CURLOPT_RESOLVE => [$resolveEntry]];
        }

        $this->http = new GuzzleClient($config);

        $this->logger->info('RestClient initialized', [
            'base_url' => $this->baseUrl,
            'has_token' => !empty($this->apiToken),
            'resolve' => $resolveEntry,
        ]);
    }

    /**
     * Build a CURLOPT_RESOLVE entry ("host:port:ip") for a URL host and override IP.
     * For non-base URLs, resolution applies only when the hostname matches the base URL.
     */
    private function buildResolveEntry(string $resolveIp, ?string $targetUrl = null): ?string
    {
        $targetUrl = $targetUrl ?? $this->baseUrl;
        $host = parse_url($targetUrl, \PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        if ($targetUrl !== $this->baseUrl) {
            $baseHost = parse_url($this->baseUrl, \PHP_URL_HOST);
            if (!is_string($baseHost) || strcasecmp($host, $baseHost) !== 0) {
                return null;
            }
        }

        $scheme = strtolower((string) parse_url($targetUrl, \PHP_URL_SCHEME));
        $port = parse_url($targetUrl, \PHP_URL_PORT);
        if (!is_int($port)) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        // Tolerate a value pasted as a full "host:port:ip" CURLOPT_RESOLVE entry —
        // take the last colon-separated token as the IP. IPv6 must be bracketed.
        $ip = $resolveIp;
        if (strpos($ip, ':') !== false && strpos($ip, '[') === false) {
            $parts = explode(':', $ip);
            $ip = (string) end($parts);
        }
        $ip = trim($ip, "[]");

        if (filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            $this->logger->warning('Ignoring invalid resolve_ip; expected a bare IP address', [
                'resolve_ip' => $resolveIp,
            ]);
            return null;
        }

        return $host . ':' . $port . ':' . $ip;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $jsonBody = []): array
    {
        return $this->request('POST', $path, [RequestOptions::JSON => $jsonBody]);
    }

    public function patch(string $path, array $jsonBody = []): array
    {
        return $this->request('PATCH', $path, [RequestOptions::JSON => $jsonBody]);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = $this->authHeaders();

        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $body = (string) $response->getBody();
            $this->logger->debug('REST ' . $method . ' response', ['status' => $response->getStatusCode(), 'body' => substr($body, 0, 500)]);
            return $this->decodeBody($body, $response->getStatusCode(), $path);
        } catch (BadResponseException $e) {
            $this->logger->error('REST ' . $method . ' failed', [
                'exception' => $e->getMessage(),
                'path' => $path,
                'code' => $e->getCode(),
            ]);

            $errorResponse = $e->getResponse();
            $errorBody = $errorResponse !== null ? (string) $errorResponse->getBody() : '';

            // A non-JSON error body (typically the site's HTML 404 page) means the
            // request never reached Joomla's API application; the Guzzle message
            // would only parrot the status code and dump the markup on the client.
            if (!$this->looksLikeJson($errorBody)) {
                throw $this->nonJsonResponseException(
                    $errorResponse !== null ? $errorResponse->getStatusCode() : 0,
                    $path,
                    $errorBody,
                    $e
                );
            }

            throw $e;
        } catch (GuzzleException $e) {
            $this->logger->error('REST ' . $method . ' failed', [
                'exception' => $e->getMessage(),
                'path' => $path,
                'code' => $e->getCode(),
            ]);
            throw $e;
        }
    }

    private function decodeBody(string $body, int $status, string $path): array
    {
        if (ltrim($body) === '') {
            return [];
        }

        if (!$this->looksLikeJson($body)) {
            throw $this->nonJsonResponseException($status, $path, $body);
        }

        return json_decode($body, true) ?? [];
    }

    private function looksLikeJson(string $body): bool
    {
        $trimmed = ltrim($body);

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    private function nonJsonResponseException(int $status, string $path, string $body, ?\Throwable $previous = null): \RuntimeException
    {
        $kind = str_starts_with(ltrim($body), '<') ? 'HTML' : 'a non-JSON response';

        // This message reaches the MCP client, so it names the API path but not
        // the Base URL, which can be an internal host; the log keeps the full URL.
        $this->logger->error('Joomla Web Services API returned a non-JSON response', [
            'url' => $this->baseUrl . '/' . ltrim($path, '/'),
            'status' => $status,
        ]);

        return new \RuntimeException(sprintf(
            'The Joomla Web Services API returned %s instead of JSON (HTTP %d) for %s. '
            . 'The request did not reach Joomla\'s API application — check the web server configuration: '
            . 'nginx needs a "location /api" block routing to /api/index.php; Apache needs .htaccess with mod_rewrite.',
            $kind,
            $status,
            ltrim($path, '/')
        ), 0, $previous);
    }

	public function fetchUrlContent(string $url, float $timeout = 30.0): string
	{
		$this->assertUrlAllowed($url);

		try {
			$config = [
				'timeout' => $timeout,
				'verify' => $this->http->getConfig('verify'),
			];

			$resolveEntry = $this->resolveIp !== null ? $this->buildResolveEntry($this->resolveIp, $url) : null;
			if ($resolveEntry !== null) {
				$config['curl'] = [\CURLOPT_RESOLVE => [$resolveEntry]];
			}

			$client = new GuzzleClient($config);
			$response = $client->request('GET', $url);
			$this->logger->debug('Fetched URL content', [
				'url' => $url,
				'status' => $response->getStatusCode(),
				'bytes' => (int) $response->getBody()->getSize(),
			]);
			return (string) $response->getBody();
		} catch (GuzzleException $e) {
			$this->logger->error('Fetch URL failed', [
				'url' => $url,
				'exception' => $e->getMessage(),
				'code' => $e->getCode(),
			]);
			throw $e;
		}
	}

    /**
     * Fetch a same-origin frontend page as an anonymous visitor.
     *
     * The URL is always this client's configured base URL plus a
     * server-constructed path (article or menu item); the host is never
     * user-controlled. assertUrlAllowed() is therefore not applied — that
     * guard still protects fetchUrlContent() (upload_media / install_extension).
     *
     * @return array{status: int, final_url: string, body: string, truncated: bool}
     */
    public function fetchRenderedPage(string $pathAndQuery): array
    {
        $url = $this->baseUrl . '/' . ltrim($pathAndQuery, '/');
        $baseHost = strtolower((string) parse_url($this->baseUrl, \PHP_URL_HOST));

        try {
            $config = [
                'timeout' => self::RENDERED_PAGE_TIMEOUT,
                'verify' => $this->http->getConfig('verify'),
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                    'on_redirect' => static function ($request, $response, $nextUri) use ($baseHost): void {
                        $targetHost = strtolower((string) (is_object($nextUri) && method_exists($nextUri, 'getHost')
                            ? $nextUri->getHost()
                            : parse_url((string) $nextUri, \PHP_URL_HOST)));
                        if ($targetHost === '' || strcasecmp($targetHost, $baseHost) !== 0) {
                            throw new \InvalidArgumentException('Refusing to follow off-site redirect');
                        }
                    },
                ],
                'stream' => true,
            ];

            $resolveEntry = $this->resolveIp !== null ? $this->buildResolveEntry($this->resolveIp, $url) : null;
            if ($resolveEntry !== null) {
                $config['curl'] = [\CURLOPT_RESOLVE => [$resolveEntry]];
            }

            $client = new GuzzleClient($config);
            $response = $client->request('GET', $url);
            $stream = $response->getBody();
            $limit = self::RENDERED_PAGE_MAX_BYTES + 1;
            $body = '';
            while (strlen($body) < $limit && !$stream->eof()) {
                $chunk = $stream->read($limit - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }

            $truncated = strlen($body) > self::RENDERED_PAGE_MAX_BYTES;
            if ($truncated) {
                $body = substr($body, 0, self::RENDERED_PAGE_MAX_BYTES);
            }

            $history = $response->getHeader('X-Guzzle-Redirect-History');
            $finalUrl = $history !== [] ? (string) end($history) : $url;

            $this->logger->debug('Fetched rendered page', [
                'url' => $url,
                'final_url' => $finalUrl,
                'status' => $response->getStatusCode(),
                'truncated' => $truncated,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'final_url' => $finalUrl,
                'body' => $body,
                'truncated' => $truncated,
            ];
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            $this->logger->error('Fetch rendered page failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Guard server-side fetches (upload_media / install_extension source_url) against SSRF.
     * Only http/https is permitted, and the host must not resolve to a private, reserved,
     * loopback or link-local address — blocking access to internal services and cloud
     * metadata endpoints (e.g. 169.254.169.254).
     */
    private function assertUrlAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http and https URLs are allowed');
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('URL is missing a host');
        }

        $host = trim($host, '[]');

        $ips = [];
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            foreach (['A', 'AAAA'] as $type) {
                foreach (@dns_get_record($host, $type === 'A' ? \DNS_A : \DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }

            if (empty($ips)) {
                $resolved = gethostbynamel($host);
                if (is_array($resolved)) {
                    $ips = $resolved;
                }
            }
        }

        if (empty($ips)) {
            throw new \InvalidArgumentException('Could not resolve URL host');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                $this->logger->warning('Blocked SSRF attempt to non-public address', ['url' => $url, 'ip' => $ip]);
                throw new \InvalidArgumentException('URL host resolves to a disallowed (private or reserved) address');
            }
        }
    }

    private function authHeaders(): array
    {
        $headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ];
        if (!empty($this->apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiToken;
            $headers['X-Joomla-Token'] = $this->apiToken;
        }
        
        return $headers;
    }
}


