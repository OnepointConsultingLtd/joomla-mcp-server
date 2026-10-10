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
use Joomla\Component\Mcpserver\Administrator\Service\MonologFactory;
use Joomla\Component\Mcpserver\Administrator\Service\RestClientFactory;
use Joomla\DI\Container;
use Joomla\Registry\Registry;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Joomla registers Psr\Log\LoggerInterface as a protected core service, and on
 * Joomla 4 (joomla/di 2.0.x) a component's child container cannot overwrite a
 * key protected in its parent: the provider threw "Key Psr\Log\LoggerInterface
 * is protected and can't be overwritten." and both entry points were dead.
 */
final class ProviderLoggerRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        ComponentHelper::$params = new Registry();
    }

    protected function tearDown(): void
    {
        ComponentHelper::reset();
    }

    public function testProviderRegistersAlongsideProtectedCoreLogger(): void
    {
        $coreLogger = new NullLogger();
        $container = new Container();
        $container->share(LoggerInterface::class, static fn () => $coreLogger, true);

        $provider = require dirname(__DIR__, 2) . '/admin/services/provider.php';
        $provider->register($container);

        $this->assertSame($coreLogger, $container->get(LoggerInterface::class));

        $componentLogger = $container->get(MonologFactory::SERVICE_KEY);
        $this->assertInstanceOf(Logger::class, $componentLogger);

        $factory = $container->get(RestClientFactory::class);
        $this->assertSame($componentLogger, (new \ReflectionProperty($factory, 'logger'))->getValue($factory));
    }
}
