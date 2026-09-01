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
use PHPUnit\Framework\TestCase;

final class JoomlaActionLogServiceTest extends TestCase
{
    private function principal(int $userId = 42): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(
            credentialId: 7,
            selector: 'sel-abc',
            userId: $userId,
            credentialName: 'CI Token',
            joomlaApiToken: 'super-secret-token-value',
        );
    }

    /**
     * @return array{calls: list<array{userId: int, messageKey: string, context: string, message: array<string, string>}>, writer: callable}
     */
    private function recordingWriter(): array
    {
        $calls = [];
        $writer = function (int $userId, string $messageKey, string $context, array $message) use (&$calls): void {
            $calls[] = ['userId' => $userId, 'messageKey' => $messageKey, 'context' => $context, 'message' => $message];
        };

        return ['calls' => &$calls, 'writer' => $writer];
    }

    public function testRecordSuccessWritesUnderAuthenticatedUserWithStableContext(): void
    {
        $rec = $this->recordingWriter();
        $service = new JoomlaActionLogService($rec['writer']);

        $service->recordSuccess($this->principal(42), 'update_article', 'com_content.article.10', 'req-123');

        $calls = $rec['calls'];
        $this->assertCount(1, $calls);
        $this->assertSame(42, $calls[0]['userId']);
        $this->assertSame('com_mcpserver.mcp.update_article', $calls[0]['messageKey']);
        $this->assertSame('com_mcpserver.mcp.update_article', $calls[0]['context']);
        $this->assertSame('update_article', $calls[0]['message']['tool']);
        $this->assertSame('com_content.article.10', $calls[0]['message']['target']);
        $this->assertSame('req-123', $calls[0]['message']['request_id']);
    }

    public function testRecordSuccessNeverForwardsPrincipalTokenToWriter(): void
    {
        $rec = $this->recordingWriter();
        $service = new JoomlaActionLogService($rec['writer']);

        $service->recordSuccess($this->principal(), 'delete_article', 'com_content.article.11', 'req-456');

        $calls = $rec['calls'];
        $this->assertCount(1, $calls);

        $serialised = json_encode($calls[0]);
        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('super-secret-token-value', $serialised);
        $this->assertArrayNotHasKey('token', $calls[0]['message']);
        $this->assertArrayNotHasKey('secret', $calls[0]['message']);
        $this->assertArrayNotHasKey('payload', $calls[0]['message']);
    }

    public function testRecordSuccessRedactsControlCharactersAndTruncatesTargetTo255Bytes(): void
    {
        $rec = $this->recordingWriter();
        $service = new JoomlaActionLogService($rec['writer']);

        $dirtyTarget = "com_content\x00\x07.article." . str_repeat('9', 300);

        $service->recordSuccess($this->principal(), 'update_article', $dirtyTarget, 'req-789');

        $calls = $rec['calls'];
        $this->assertCount(1, $calls);

        $storedTarget = $calls[0]['message']['target'];
        $this->assertStringNotContainsString("\x00", $storedTarget);
        $this->assertStringNotContainsString("\x07", $storedTarget);
        $this->assertLessThanOrEqual(255, strlen($storedTarget));
    }

    public function testRecordSuccessAllowsNullTargetAsEmptyString(): void
    {
        $rec = $this->recordingWriter();
        $service = new JoomlaActionLogService($rec['writer']);

        $service->recordSuccess($this->principal(), 'clear_cache', null, 'req-000');

        $calls = $rec['calls'];
        $this->assertCount(1, $calls);
        $this->assertSame('', $calls[0]['message']['target']);
    }

    public function testRecordSuccessRejectsToolNameWithInvalidFormatWithoutWriting(): void
    {
        $rec = $this->recordingWriter();
        $service = new JoomlaActionLogService($rec['writer']);

        $service->recordSuccess($this->principal(), 'Update Article; DROP TABLE', 'com_content.article.10', 'req-1');
        $service->recordSuccess($this->principal(), '', 'com_content.article.10', 'req-2');

        $this->assertCount(0, $rec['calls']);
    }

    public function testRecordSuccessSwallowsWriterFailureWithoutPropagating(): void
    {
        $service = new JoomlaActionLogService(static function (): void {
            throw new \RuntimeException('action log table unavailable');
        });

        $service->recordSuccess($this->principal(), 'update_article', 'com_content.article.10', 'req-1');

        $this->assertTrue(true);
    }
}
