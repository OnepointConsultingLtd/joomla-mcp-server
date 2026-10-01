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
 * Turns a tool's progress points into notifications/progress for one request:
 * strictly increasing, throttled, and a point at which a departed client stops
 * the work.
 */
final class ProgressReporter
{
    private ?float $last = null;

    private ?float $lastSentAt = null;

    /**
     * @param  \Closure(): float  $clock  seconds
     */
    public function __construct(
        private readonly string|int $token,
        private readonly ProgressSink $sink,
        private readonly \Closure $clock,
        private readonly float $minIntervalSeconds = 0.25
    ) {
    }

    /**
     * @throws ProgressCancelled when the client has disconnected
     */
    public function report(float $progress, ?float $total = null, ?string $message = null, bool $final = false): void
    {
        // The spec requires progress to increase with every notification.
        if ($this->last !== null && $progress <= $this->last) {
            return;
        }

        $now = ($this->clock)();
        // The last point always goes out, so the client sees where the work ended.
        $final = $final || ($total !== null && $progress >= $total);
        if (!$final && $this->lastSentAt !== null && $now - $this->lastSentAt < $this->minIntervalSeconds) {
            return;
        }

        $params = ['progressToken' => $this->token, 'progress' => $progress];
        if ($total !== null) {
            $params['total'] = $total;
        }
        if ($message !== null) {
            $params['message'] = $message;
        }

        $this->sink->send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => $params]);
        $this->last = $progress;
        $this->lastSentAt = $now;

        // Only a write reveals a disconnect, so this is where the work can stop.
        if ($this->sink->isAborted()) {
            throw new ProgressCancelled('The client closed the request');
        }
    }
}
