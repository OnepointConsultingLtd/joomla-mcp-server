<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ProgressCancelled;
use Joomla\Component\Mcpserver\Administrator\Service\ProgressReporter;
use PHPUnit\Framework\TestCase;

class ProgressReporterTest extends TestCase
{
    private float $now = 100.0;

    public function testSendsAProgressNotificationWithTheToken(): void
    {
        $sink = new RecordingProgressSink();
        $this->reporter($sink)->report(1, 4, 'Fetched page 1 of 4');

        $this->assertSame([[
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 'tok', 'progress' => 1.0, 'total' => 4.0, 'message' => 'Fetched page 1 of 4'],
        ]], $sink->sent);
    }

    public function testThrottlesWithinTheInterval(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(1, 10);
        $this->now += 0.1;
        $reporter->report(2, 10);
        $this->now += 0.3;
        $reporter->report(3, 10);

        $this->assertSame([1.0, 3.0], array_column(array_column($sink->sent, 'params'), 'progress'));
    }

    public function testTheFinalNotificationIsNeverThrottled(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(1, 2);
        $reporter->report(2, 2);

        $this->assertCount(2, $sink->sent);
    }

    public function testAFinalReportBypassesTheThrottleWhenTheTotalIsUnknown(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(1);
        $reporter->report(2, null, null, true);

        $this->assertCount(2, $sink->sent);
    }

    public function testProgressNeverGoesBackwards(): void
    {
        $sink = new RecordingProgressSink();
        $reporter = $this->reporter($sink);

        $reporter->report(5);
        $this->now += 1;
        $reporter->report(5);
        $this->now += 1;
        $reporter->report(3);

        $this->assertCount(1, $sink->sent);
    }

    public function testOptionalFieldsAreOmitted(): void
    {
        $sink = new RecordingProgressSink();
        $this->reporter($sink)->report(1);

        $this->assertSame(['progressToken' => 'tok', 'progress' => 1.0], $sink->sent[0]['params']);
    }

    public function testADisconnectedClientCancelsTheWork(): void
    {
        $this->expectException(ProgressCancelled::class);

        $this->reporter(new RecordingProgressSink(abortAfterFirst: true))->report(1, 3);
    }

    private function reporter(RecordingProgressSink $sink): ProgressReporter
    {
        return new ProgressReporter('tok', $sink, fn (): float => $this->now);
    }
}
