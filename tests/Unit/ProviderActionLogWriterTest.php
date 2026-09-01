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
use Joomla\Component\Mcpserver\Administrator\Service\JoomlaActionLogService;
use Joomla\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the JoomlaActionLogService::class wiring in admin/services/provider.php:
 * the injected writer must safely no-op when com_actionlogs is unavailable, and must
 * call the real Joomla Action Log API with principal attribution when it is available.
 */
final class ProviderActionLogWriterTest extends TestCase
{
    private function loadProviderContainer(): Container
    {
        $container = new Container();
        $provider = require dirname(__DIR__, 2) . '/admin/services/provider.php';
        $provider->register($container);

        return $container;
    }

    private function principal(int $userId): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(
            credentialId: 1,
            selector: 'sel-abc',
            userId: $userId,
            credentialName: 'CI Token',
            joomlaApiToken: 'super-secret-token-value',
        );
    }

    /**
     * Defines a test double for the real com_actionlogs model at runtime (not at file
     * compile time), so this single test can first prove the "unavailable" no-op path
     * against the true absence of the class, then prove the "available" path once the
     * class has been made to exist, in a guaranteed order.
     */
    private function defineActionlogModelDoubleIfAbsent(): void
    {
        $modelClass = '\\Joomla\\Component\\Actionlogs\\Administrator\\Model\\ActionlogModel';

        if (class_exists($modelClass)) {
            return;
        }

        eval('
            namespace Joomla\\Component\\Actionlogs\\Administrator\\Model;

            final class ActionlogModel
            {
                /** @var list<array{messages: array, messageKey: string, context: string, userId: int}> */
                public static array $calls = [];

                public function addLog(array $messages, string $messageKey, string $context, $userId = 0): void
                {
                    self::$calls[] = [
                        "messages" => $messages,
                        "messageKey" => $messageKey,
                        "context" => $context,
                        "userId" => $userId,
                    ];
                }
            }
        ');
    }

    public function testProviderResolvesJoomlaActionLogServiceAsSharedSingleton(): void
    {
        $container = $this->loadProviderContainer();

        $first = $container->get(JoomlaActionLogService::class);
        $second = $container->get(JoomlaActionLogService::class);

        $this->assertInstanceOf(JoomlaActionLogService::class, $first);
        $this->assertSame($first, $second);
    }

    public function testInjectedWriterNoOpsWhenActionLogsModuleIsUnavailableThenCallsRealApiWhenPresent(): void
    {
        $modelClass = '\\Joomla\\Component\\Actionlogs\\Administrator\\Model\\ActionlogModel';
        $container = $this->loadProviderContainer();
        $service = $container->get(JoomlaActionLogService::class);
        $principal = $this->principal(99);

        // com_actionlogs is not installed/available: the writer must be a safe no-op
        // and must never throw or otherwise disrupt the caller.
        if (!class_exists($modelClass)) {
            $service->recordSuccess($principal, 'update_article', 'com_content.article.5', 'req-absent');
        }

        // Now make the real Joomla Action Log API available and verify the writer
        // calls it with the authenticated principal's user id and the supplied
        // message/context, using the real com_mcpserver extension/action mapping.
        $this->defineActionlogModelDoubleIfAbsent();
        $modelClass::$calls = [];

        $service->recordSuccess($principal, 'update_article', 'com_content.article.5', 'req-present');

        $calls = $modelClass::$calls;
        $this->assertCount(1, $calls);
        $this->assertSame(99, $calls[0]['userId']);
        $this->assertSame('com_mcpserver.mcp.update_article', $calls[0]['messageKey']);
        $this->assertSame('com_mcpserver.mcp.update_article', $calls[0]['context']);
        $this->assertSame('update_article', $calls[0]['messages'][0]['action']);
        $this->assertSame('com_content.article.5', $calls[0]['messages'][0]['id']);
        $this->assertSame('com_mcpserver', $calls[0]['messages'][0]['extension']);
    }

    public function testInjectedWriterNeverForwardsPrincipalTokenToTheJoomlaApi(): void
    {
        $modelClass = '\\Joomla\\Component\\Actionlogs\\Administrator\\Model\\ActionlogModel';
        $this->defineActionlogModelDoubleIfAbsent();
        $modelClass::$calls = [];

        $container = $this->loadProviderContainer();
        $service = $container->get(JoomlaActionLogService::class);

        $service->recordSuccess($this->principal(7), 'delete_article', 'com_content.article.11', 'req-456');

        $calls = $modelClass::$calls;
        $this->assertCount(1, $calls);

        $serialised = json_encode($calls[0]);
        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('super-secret-token-value', $serialised);
    }
}
