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
 * Streams progress as Server-Sent Events, starting the stream only when the
 * first notification arrives — a call that reports nothing stays a plain JSON
 * response. The closures keep header()/echo/flush() out of this class.
 */
final class HttpProgressSink implements ProgressSink
{
    private bool $started = false;

    /**
     * @param  \Closure(): void        $start    sends the event-stream status and headers
     * @param  \Closure(string): void  $write    writes and flushes bytes
     * @param  \Closure(): bool        $aborted  whether the client has disconnected
     */
    public function __construct(
        private readonly \Closure $start,
        private readonly \Closure $write,
        private readonly \Closure $aborted
    ) {
    }

    public function send(array $notification): void
    {
        if (!$this->started) {
            ($this->start)();
            $this->started = true;
        }

        ($this->write)(McpHttpTransport::sseFrames([$notification]));
    }

    /**
     * Flush and close the output buffers that would hold the stream back. A buffer
     * opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be closed — ob_end_flush()
     * fails and the level stays put — so stop there instead of spinning; anything
     * it holds is merely delivered later.
     *
     * @param  (\Closure(): int)|null   $level     defaults to ob_get_level()
     * @param  (\Closure(): bool)|null  $endFlush  defaults to ob_end_flush()
     */
    public static function drainOutputBuffers(?\Closure $level = null, ?\Closure $endFlush = null): void
    {
        $level ??= static fn (): int => ob_get_level();
        $endFlush ??= static fn (): bool => @ob_end_flush();

        while ($level() > 0 && $endFlush() !== false) {
        }
    }

    public function isAborted(): bool
    {
        return $this->started && ($this->aborted)();
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * End the stream with the response — unless the client already left, in
     * which case nothing more may be sent for the request.
     *
     * @param  array<string, mixed>  $response
     */
    public function finish(array $response): void
    {
        if ($this->started && !($this->aborted)()) {
            ($this->write)(McpHttpTransport::sseFrames([$response]));
        }
    }
}
