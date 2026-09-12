<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Throwable;

/**
 * Emits a com_mcpserver Joomla Action Log entry for successful mutating MCP
 * operations, attributed to the authenticated Joomla user.
 *
 * The Joomla Action Log writer is injected as a callable rather than
 * resolved via Factory, so this service stays independent of the global
 * Joomla application state and is trivially fakeable in tests. Callers
 * decide which tools are mutating and allowlisted for logging; this service
 * only validates that the tool name is safe to embed in a stable message
 * key/context string, sanitises the target identifier, and never forwards
 * secrets (tokens, payloads) to the writer. A write failure is swallowed:
 * it must never fail the MCP operation it is reporting on, and unsuccessful
 * operations are never logged at all (callers only invoke recordSuccess()
 * after a mutation has actually succeeded).
 */
final class JoomlaActionLogService
{
    private const CONTEXT_PREFIX = 'com_mcpserver.mcp';

    private const TARGET_MAX_LENGTH = 255;

    private const REQUEST_ID_MAX_LENGTH = 64;

    /**
     * @param  callable(int $userId, string $messageKey, string $context, array<string, string> $message): void  $writer
     */
    public function __construct(
        private $writer,
    ) {
    }

    public function recordSuccess(
        AuthenticatedPrincipal $principal,
        string $tool,
        ?string $target,
        string $requestId,
    ): void {
        if (!self::isValidToolName($tool)) {
            return;
        }

        $context = self::CONTEXT_PREFIX . '.' . $tool;

        $message = [
            'tool' => $tool,
            'target' => self::sanitizeTarget($target) ?? '',
            'request_id' => substr($requestId, 0, self::REQUEST_ID_MAX_LENGTH),
        ];

        try {
            ($this->writer)($principal->userId, $context, $context, $message);
        } catch (Throwable) {
            // A logging failure must never fail the mutation it reports on.
        }
    }

    private static function isValidToolName(string $tool): bool
    {
        return $tool !== '' && preg_match('/^[a-z0-9_]+$/', $tool) === 1;
    }

    private static function sanitizeTarget(?string $target): ?string
    {
        if ($target === null) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $target);
        $clean = $clean ?? '';

        return substr($clean, 0, self::TARGET_MAX_LENGTH);
    }
}
