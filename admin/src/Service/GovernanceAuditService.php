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

/**
 * Persists one immutable-ish audit row per governed MCP request into
 * #__mcpserver_request_log, attributing it to the AuthenticatedPrincipal
 * that made it when one is available.
 *
 * Attribution columns (credential_id, user_id, credential_selector) are
 * nullable: requests made under the legacy shared-token mode, or requests
 * that fail authentication before a principal is resolved, are recorded
 * with null attribution rather than being dropped. The row never carries a
 * token, secret, or arbitrary request/response content — only the
 * caller-supplied target identifier, which is sanitised before storage.
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
     * @param  callable(): DateTimeImmutable  $clock  Supplies the request timestamp.
     */
    public function __construct(
        private DatabaseInterface $db,
        private $clock,
    ) {
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
