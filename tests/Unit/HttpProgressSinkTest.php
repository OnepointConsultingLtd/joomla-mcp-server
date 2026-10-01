<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\HttpProgressSink;
use PHPUnit\Framework\TestCase;

class HttpProgressSinkTest extends TestCase
{
    private int $starts = 0;

    private string $written = '';

    private bool $aborted = false;

    public function testStartsTheStreamOnceAndWritesFrames(): void
    {
        $sink = $this->sink();

        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 1, 'progress' => 1]]);
        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 1, 'progress' => 2]]);
        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame(1, $this->starts);
        $this->assertSame(3, substr_count($this->written, "event: message\ndata: "));
        $this->assertStringEndsWith("\"id\":1,\"result\":[]}\n\n", $this->written);
        $this->assertTrue($sink->hasStarted());
    }

    public function testNothingIsWrittenAfterTheClientLeft(): void
    {
        $sink = $this->sink();
        $sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []]);
        $this->aborted = true;

        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame(1, substr_count($this->written, 'event: message'));
        $this->assertTrue($sink->isAborted());
    }

    public function testAnUnstartedSinkWritesNothingAndIsNeverAborted(): void
    {
        $sink = $this->sink();
        $this->aborted = true;

        $sink->finish(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertSame('', $this->written);
        $this->assertFalse($sink->isAborted());
        $this->assertFalse($sink->hasStarted());
    }

    public function testDrainingStopsAtABufferThatCannotBeRemoved(): void
    {
        // A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE makes ob_end_flush()
        // fail and leaves the level unchanged; draining must stop, not spin.
        $level = 3;
        $calls = 0;
        HttpProgressSink::drainOutputBuffers(
            static fn (): int => $level,
            static function () use (&$level, &$calls): bool {
                $calls++;
                if ($level === 2) {
                    return false;
                }
                $level--;

                return true;
            }
        );

        $this->assertSame(2, $level);
        $this->assertSame(2, $calls);
    }

    private function sink(): HttpProgressSink
    {
        return new HttpProgressSink(
            function (): void { $this->starts++; },
            function (string $bytes): void { $this->written .= $bytes; },
            fn (): bool => $this->aborted
        );
    }
}
