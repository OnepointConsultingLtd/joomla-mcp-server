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
 * A validated authorisation request, waiting for the user's consent.
 *
 * It is kept in the user's session between the consent page and the form
 * post, never round-tripped through hidden fields, so the user cannot be
 * shown one client and redirect URI and have the post approve another.
 */
final class OAuthAuthorizationRequest
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly ?string $state,
        public readonly string $scope,
        public readonly string $resource,
    ) {
    }

    /**
     * Where the authorisation code will be sent, for the consent page: the
     * host of an https URI, otherwise the whole URI, since an app scheme or a
     * loopback address says little on its own.
     */
    public function redirectTarget(): string
    {
        $parts = parse_url($this->redirectUri);

        if (strtolower((string) ($parts['scheme'] ?? '')) === 'https' && isset($parts['host'])) {
            return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return $this->redirectUri;
    }

    /**
     * The host a Client ID Metadata Document client's identity was fetched
     * from, which unlike its name this site has verified; null for a client
     * that registered itself.
     */
    public function verifiedClientHost(): ?string
    {
        if (!str_starts_with($this->clientId, 'https://')) {
            return null;
        }

        $host = parse_url($this->clientId, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    /**
     * @return array<string,string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['clientId', 'clientName', 'redirectUri', 'codeChallenge', 'scope', 'resource'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new \InvalidArgumentException('Incomplete authorisation request');
            }
        }

        return new self(
            $data['clientId'],
            $data['clientName'],
            $data['redirectUri'],
            $data['codeChallenge'],
            isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            $data['scope'],
            $data['resource'],
        );
    }
}
