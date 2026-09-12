<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;

/**
 * Read-only query surface over #__mcpserver_request_log for the governed
 * audit dashboard. Selects only the safe, non-sensitive audit columns: no
 * request/response body, content, token, secret, or bearer value is ever
 * stored in this table or selected here.
 */
final class GovernanceAuditQueryService
{
    private const TABLE = '#__mcpserver_request_log';

    private const USERS_TABLE = '#__users';

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 100;

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

    private const DATE_ONLY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Safe audit columns exposed to the UI. Deliberately excludes any
     * credential secret/body/content column.
     */
    private const SAFE_COLUMNS = [
        'id',
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

    public function __construct(
        private DatabaseInterface $db,
    ) {
    }

    /**
     * @param  array{userId?: ?int, toolName?: ?string, dateFrom?: ?string, dateTo?: ?string}  $filters
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException  When dateFrom/dateTo is not a valid Y-m-d [H:i:s] value.
     */
    public function search(array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $db = $this->db;

        $auditColumns = array_map(
            static fn (string $column): string => (string) $db->quoteName('audit.' . $column),
            self::SAFE_COLUMNS
        );
        $auditColumns[] = (string) $db->quoteName('users.name', 'user_name');

        $query = $db->getQuery(true)
            ->select($auditColumns)
            ->from($db->quoteName(self::TABLE, 'audit'))
            ->join(
                'LEFT',
                $db->quoteName(self::USERS_TABLE, 'users'),
                $db->quoteName('users.id') . ' = ' . $db->quoteName('audit.user_id')
            );

        $userId = $filters['userId'] ?? null;
        if ($userId !== null) {
            $query->where($db->quoteName('audit.user_id') . ' = ' . (int) $userId);
        }

        $toolName = $filters['toolName'] ?? null;
        if ($toolName !== null && $toolName !== '') {
            $query->where($db->quoteName('audit.tool_name') . ' = ' . $db->quote((string) $toolName));
        }

        $dateFrom = $filters['dateFrom'] ?? null;
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where($db->quoteName('audit.created') . ' >= ' . $db->quote($this->assertValidDate((string) $dateFrom)));
        }

        $dateTo = $filters['dateTo'] ?? null;
        if ($dateTo !== null && $dateTo !== '') {
            $validatedDateTo = $this->assertValidDate((string) $dateTo);
            // A date-only value (e.g. from the UI's <input type="date">) means
            // "through the end of that day", not "through its midnight instant" —
            // expand to 23:59:59 so the entire selected UTC day is included.
            if (preg_match(self::DATE_ONLY_PATTERN, $validatedDateTo) === 1) {
                $validatedDateTo .= ' 23:59:59';
            }
            $query->where($db->quoteName('audit.created') . ' <= ' . $db->quote($validatedDateTo));
        }

        $query->order($db->quoteName('audit.id') . ' DESC');

        $clampedLimit = max(self::MIN_LIMIT, min(self::MAX_LIMIT, $limit));

        $db->setQuery($query, max(0, $offset), $clampedLimit);

        return $db->loadAssocList() ?: [];
    }

    private function assertValidDate(string $value): string
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid date filter value: %s', $value));
        }

        return $value;
    }
}
