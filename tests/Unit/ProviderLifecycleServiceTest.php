<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialCipher;
use Joomla\Component\Mcpserver\Administrator\Service\CredentialLifecycleService;
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaCredentialLifecycleStore;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\DI\Container;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

final class ProviderLifecycleServiceTestDb implements DatabaseInterface
{
    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        return $name;
    }

    public function quote(array|string $text, bool $escape = true): array|string
    {
        return $text;
    }

    public function getQuery(bool $new = false): QueryInterface|string
    {
        return '';
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        return $this;
    }

    public function loadAssoc(): ?array
    {
        return null;
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function execute(): bool
    {
        return true;
    }
}

final class ProviderLifecycleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::reset();
        ComponentHelper::reset();
    }

    private function loadProviderContainer(): Container
    {
        Factory::$dbo = new ProviderLifecycleServiceTestDb();
        Factory::$application = new Registry(['secret' => 'joomla-site-secret-value']);
        ComponentHelper::$params = new Registry([
            'credential_salt' => base64_encode('component-salt-bytes-0123456789'),
        ]);

        $container = new Container();
        $provider = require dirname(__DIR__, 2) . '/admin/services/provider.php';
        $provider->register($container);

        return $container;
    }

    public function testContainerResolvesCredentialLifecycleServiceWithFactoryProvisionedDependencies(): void
    {
        $container = $this->loadProviderContainer();

        $service = $container->get(CredentialLifecycleService::class);

        $this->assertInstanceOf(CredentialLifecycleService::class, $service);
    }

    public function testCredentialLifecycleServiceUsesTheSharedCredentialCipherRegistration(): void
    {
        $container = $this->loadProviderContainer();

        $reflection = new \ReflectionProperty(CredentialLifecycleService::class, 'cipher');
        $reflection->setAccessible(true);

        $service = $container->get(CredentialLifecycleService::class);
        $cipher = $container->get(CredentialCipher::class);

        $this->assertSame($cipher, $reflection->getValue($service));
    }

    public function testCredentialLifecycleServiceUsesTheRegisteredLifecycleStore(): void
    {
        $container = $this->loadProviderContainer();

        $reflection = new \ReflectionProperty(CredentialLifecycleService::class, 'store');
        $reflection->setAccessible(true);

        $service = $container->get(CredentialLifecycleService::class);
        $store = $container->get(JoomlaCredentialLifecycleStore::class);

        $this->assertSame($store, $reflection->getValue($service));
    }

    public function testCredentialLifecycleStoreIsRegisteredAndSharedAsSingleton(): void
    {
        $container = $this->loadProviderContainer();

        $first = $container->get(JoomlaCredentialLifecycleStore::class);
        $second = $container->get(JoomlaCredentialLifecycleStore::class);

        $this->assertInstanceOf(JoomlaCredentialLifecycleStore::class, $first);
        $this->assertSame($first, $second);
    }

    public function testCredentialLifecycleServiceIsSharedAsSingleton(): void
    {
        $container = $this->loadProviderContainer();

        $first = $container->get(CredentialLifecycleService::class);
        $second = $container->get(CredentialLifecycleService::class);

        $this->assertSame($first, $second);
    }
}
