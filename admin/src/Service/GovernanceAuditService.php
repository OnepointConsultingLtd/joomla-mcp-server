<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Persists exactly one row per MCP request into #__mcpserver_request_log,
 * attributing it to the AuthenticatedPrincipal that made it when one is
 * available.
 *
 * This is the sole writer for that table. It supersedes MetricsService::record(),
 * which wrote the same nine base columns and previously ran alongside this
 * service, producing two rows per request and double-counting every dashboard
 * figure. MetricsService remains the reader (summary cards, charts, top tools)
 * and the admin-triggered prune lives in GovernanceAuditRetentionService.
 *
 * Attribution columns (credential_id, user_id, credential_selector) are
 * nullable: requests made under the legacy shared-token mode, or requests
 * that fail authentication before a principal is resolved, are recorded
 * with null attribution rather than being dropped. The row never carries a
 * token, secret, or arbitrary request/response content — only caller-supplied
 * identifiers (method, tool name, JSON-RPC request id, target), all of which
 * are length-bounded and SQL-quoted; `target` is additionally stripped of
 * control characters.
 */
final class GovernanceAuditService
{
    private const TABLE = '#__mcpserver_request_log';

    private const TARGET_MAX_LENGTH = 255;

    /**
     * Valid status values stored in the `status` column.
     */
    private const STATUSES = ['ok', 'error', 'blocked', 'auth_failed', 'rate_limited', 'invalid_request'];

    /**
     * @param  callable(): DateTimeImmutable  $clock   Supplies the request timestamp.
     * @param  Registry|null                  $params  Component params. When null the
     *                                                 service always records — the default
     *                                                 keeps unit tests independent of config.
     */
    public function __construct(
        private DatabaseInterface $db,
        private $clock,
        private ?Registry $params = null,
    ) {
    }

    /**
     * Whether a row should be written for this request.
     *
     * `metrics_enabled` remains the operator's off-switch for request logging,
     * inherited from MetricsService so disabling metrics still suppresses rows
     * exactly as it did before this service took over the write. The one
     * exception is an attributed request: under governed mode the audit trail
     * is the feature's entire purpose, so it must not be silently disableable
     * via an unrelated metrics toggle.
     */
    private function shouldRecord(?AuthenticatedPrincipal $principal): bool
    {
        if ($principal !== null) {
            return true;
        }

        return $this->params === null || (bool) $this->params->get('metrics_enabled', 1);
    }

    public function record(
        string $method,
        ?string $toolName,
        string $status,
        ?int $errorCode,
        int $httpStatus,
        int $durationMs,
        string $clientIp,
        string $context,
        ?AuthenticatedPrincipal $principal = null,
        ?string $requestId = null,
        ?string $target = null,
    ): void {
        if (!$this->shouldRecord($principal)) {
            return;
        }

        $db = $this->db;

        $normalisedStatus = in_array($status, self::STATUSES, true) ? $status : '';
        $created = ($this->clock)()->format('Y-m-d H:i:s');

        $columns = [
            'created',
            'method',
            'tool_name',
            'status',
            'error_code',
            'http_status',
            'duration_ms',
            'client_ip',
            'context',
            'request_id',
            'credential_id',
            'user_id',
            'credential_selector',
            'target',
        ];

        $values = [
            $db->quote($created),
            $db->quote(substr($method, 0, 64)),
            $db->quote(substr((string) $toolName, 0, 128)),
            $db->quote($normalisedStatus),
            $this->quoteNullableInt($errorCode),
            (string) max(0, $httpStatus),
            (string) max(0, $durationMs),
            $db->quote(substr($clientIp, 0, 45)),
            $db->quote(substr($context, 0, 10)),
            $this->quoteNullableString($requestId, 64),
            $this->quoteNullableInt($principal?->credentialId),
            $this->quoteNullableInt($principal?->userId),
            $this->quoteNullableString($principal?->selector, 128),
            $this->quoteNullableString(self::sanitizeTarget($target), self::TARGET_MAX_LENGTH),
        ];

        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));

        $db->setQuery($query)->execute();

        // Opportunistic pruning (~1% of writes) keeps the table bounded without
        // a cron dependency. Inherited from MetricsService::record(), which used
        // to own this; it moved here with the write so the behaviour survives.
        if (random_int(1, 100) === 1) {
            $this->pruneOpportunistically();
        }
    }

    /**
     * Best-effort trim of rows older than the configured retention window.
     *
     * Never throws: a failure to prune must not fail the request that triggered
     * it, and the admin-triggered GovernanceAuditRetentionService remains the
     * authoritative, reporting path.
     */
    private function pruneOpportunistically(): void
    {
        if ($this->params === null) {
            return;
        }

        try {
            $days = max(1, (int) $this->params->get('metrics_retention_days', 360));
            $cutoff = ($this->clock)()
                ->modify('-' . $days . ' day')
                ->format('Y-m-d H:i:s');

            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('created') . ' < ' . $this->db->quote($cutoff));

            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            // Pruning is opportunistic; the retention service reports failures.
        }
    }

    private function quoteNullableInt(?int $value): string
    {
        return $value === null ? 'NULL' : (string) $value;
    }

    private function quoteNullableString(?string $value, int $maxLength): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return (string) $this->db->quote(substr($value, 0, $maxLength));
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
