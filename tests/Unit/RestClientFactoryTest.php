<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\AuthenticatedPrincipal;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RestClientFactory;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RestClientFactoryTest extends TestCase
{
    private function tokenOf(RestClient $client): ?string
    {
        $reflection = new \ReflectionProperty(RestClient::class, 'apiToken');
        $reflection->setAccessible(true);

        return $reflection->getValue($client);
    }

    private function baseUrlOf(RestClient $client): string
    {
        return $client->getBaseUrl();
    }

    public function testCreateForPrincipalUsesThePrincipalsOwnToken(): void
    {
        $params = new Registry([
            'base_url' => 'https://example.test',
            'api_token' => 'shared-config-token',
            'verify_ssl' => true,
        ]);

        $factory = new RestClientFactory($params, new NullLogger());
        $principal = new AuthenticatedPrincipal(1, 'selector', 2, 'Client', 'principal-token');

        $client = $factory->createForPrincipal($principal);

        $this->assertSame('principal-token', $this->tokenOf($client));
        $this->assertSame('https://example.test', $this->baseUrlOf($client));
    }

    public function testCreateForPrincipalNeverUsesTheSharedConfiguredToken(): void
    {
        $params = new Registry([
            'base_url' => 'https://example.test',
            'api_token' => 'shared-config-token',
            'verify_ssl' => true,
        ]);

        $factory = new RestClientFactory($params, new NullLogger());
        $principal = new AuthenticatedPrincipal(1, 'selector', 2, 'Client', 'principal-token');

        $client = $factory->createForPrincipal($principal);

        $this->assertNotSame('shared-config-token', $this->tokenOf($client));
    }

    public function testCreateSharedUsesTheConfiguredApiToken(): void
    {
        $params = new Registry([
            'base_url' => 'https://example.test',
            'api_token' => 'shared-config-token',
            'verify_ssl' => true,
        ]);

        $factory = new RestClientFactory($params, new NullLogger());

        $client = $factory->createShared();

        $this->assertSame('shared-config-token', $this->tokenOf($client));
        $this->assertSame('https://example.test', $this->baseUrlOf($client));
    }
}
