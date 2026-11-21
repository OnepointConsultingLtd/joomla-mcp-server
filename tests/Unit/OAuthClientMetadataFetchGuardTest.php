<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\HttpClientMetadataFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The site fetches a URL any visitor names (the client_id), so it must not be
 * usable to reach the server's own network.
 */
final class OAuthClientMetadataFetchGuardTest extends TestCase
{
    /**
     * @return array<string,array{0:list<string>}>
     */
    public static function internalAddresses(): array
    {
        return [
            'loopback' => [['127.0.0.1']],
            'private 10/8' => [['10.1.2.3']],
            'private 172.16/12' => [['172.20.0.5']],
            'private 192.168/16' => [['192.168.1.10']],
            'link-local / cloud metadata' => [['169.254.169.254']],
            'IPv6 loopback' => [['::1']],
            'IPv6 unique local' => [['fd12:3456::1']],
            'IPv4-mapped loopback' => [['::ffff:127.0.0.1']],
            'unspecified' => [['0.0.0.0']],
            'one public, one private' => [['104.18.0.1', '10.0.0.1']],
            'nothing resolved' => [[]],
        ];
    }

    /**
     * @dataProvider internalAddresses
     */
    public function testHostsResolvingToInternalAddressesAreRefused(array $ips): void
    {
        $this->assertNotNull(HttpClientMetadataFetcher::refusal('https://client.example/metadata', $ips));
    }

    public function testHostResolvingToPublicAddressesIsAllowed(): void
    {
        $this->assertNull(HttpClientMetadataFetcher::refusal('https://claude.ai/oauth/metadata', ['160.79.104.10', '2607:6bc0::10']));
    }

    public function testOnlyHttpsOnTheDefaultPortIsFetched(): void
    {
        $this->assertNotNull(HttpClientMetadataFetcher::refusal('http://claude.ai/oauth/metadata', ['160.79.104.10']));
        $this->assertNotNull(HttpClientMetadataFetcher::refusal('https://claude.ai:8443/oauth/metadata', ['160.79.104.10']));
    }

    public function testIpLiteralHostsAreRefused(): void
    {
        $this->assertNotNull(HttpClientMetadataFetcher::refusal('https://160.79.104.10/metadata', ['160.79.104.10']));
    }
}
