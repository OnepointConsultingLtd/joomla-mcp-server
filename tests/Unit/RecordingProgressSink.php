<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\ProgressSink;

/**
 * Records notifications; optionally reports the client gone after the first.
 */
final class RecordingProgressSink implements ProgressSink
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function __construct(private readonly bool $abortAfterFirst = false)
    {
    }

    public function send(array $notification): void
    {
        $this->sent[] = $notification;
    }

    public function isAborted(): bool
    {
        return $this->abortAfterFirst && $this->sent !== [];
    }
}
