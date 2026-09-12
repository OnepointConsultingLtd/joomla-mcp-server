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
 *
 * Rows can additionally be restricted to a single Joomla account via
 * withUserScope(), which the dashboard uses to keep a non-Super-User to
 * their own requests.
 */
final class GovernanceAuditQueryService
{
    private const TABLE = '#__mcpserver_request_log';

    private const USERS_TABLE = '#__users';

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 100;

    /**
     * Ceiling on the User filter's dropdown. Generous enough to cover any
     * realistic set of MCP users while keeping a runaway log from rendering
     * a select with thousands of entries.
     */
    private const MAX_USER_OPTIONS = 500;

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

    /**
     * Joomla user id that every search is restricted to, or null to search
     * the whole audit trail.
     */
    private ?int $userScope = null;

    public function __construct(
        private DatabaseInterface $db,
    ) {
    }

    /**
     * Return a copy of this service whose searches only see rows attributed
     * to $userId; pass null for the unrestricted view.
     *
     * This is an authorisation boundary, not a filter: it is applied on top
     * of the caller-supplied `userId` filter and can only narrow the result,
     * so a restricted viewer cannot widen their view by supplying a
     * different id in the request. The instance is cloned so the DI
     * container's shared, unscoped service is never mutated.
     */
    public function withUserScope(?int $userId): self
    {
        $scoped = clone $this;
        $scoped->userScope = $userId;

        return $scoped;
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

        // The scope is ANDed independently of the caller's userId filter so
        // the two can only intersect: a restricted viewer asking for someone
        // else's id gets no rows rather than that user's rows.
        if ($this->userScope !== null) {
            $query->where($db->quoteName('audit.user_id') . ' = ' . (int) $this->userScope);
        }

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

    /**
     * The distinct Joomla accounts that appear in the audit trail, for the
     * User filter's dropdown.
     *
     * Drawn from the log rather than from #__users because the filter's job
     * is to narrow these rows: listing every account on the site would offer
     * mostly choices that return nothing, and on a large site would be
     * unusable. Rows with a null user_id (legacy shared-token mode, or a
     * failure before a principal was resolved) belong to no account and are
     * excluded — they are reached by not filtering, not by picking a user.
     *
     * `user_name` is null when the account has since been deleted from
     * Joomla; the caller is expected to fall back to the id so a deleted
     * user's activity stays attributable.
     *
     * @return list<array{user_id: int, user_name: ?string}>
     */
    public function getAttributedUsers(int $limit = self::MAX_USER_OPTIONS): array
    {
        $db = $this->db;

        $query = $db->getQuery(true)
            ->select([
                (string) $db->quoteName('audit.user_id'),
                (string) $db->quoteName('users.name', 'user_name'),
            ])
            ->from($db->quoteName(self::TABLE, 'audit'))
            ->join(
                'LEFT',
                $db->quoteName(self::USERS_TABLE, 'users'),
                $db->quoteName('users.id') . ' = ' . $db->quoteName('audit.user_id')
            )
            ->where($db->quoteName('audit.user_id') . ' IS NOT NULL')
            ->group([
                (string) $db->quoteName('audit.user_id'),
                (string) $db->quoteName('users.name'),
            ])
            ->order($db->quoteName('users.name') . ' ASC');

        // Honoured even though only an unrestricted viewer is offered this
        // filter: a scoped caller must never be able to enumerate who else
        // has used the server.
        if ($this->userScope !== null) {
            $query->where($db->quoteName('audit.user_id') . ' = ' . (int) $this->userScope);
        }

        $db->setQuery($query, 0, max(self::MIN_LIMIT, min(self::MAX_USER_OPTIONS, $limit)));

        $rows = $db->loadAssocList() ?: [];

        return array_map(
            static fn (array $row): array => [
                'user_id'   => (int) $row['user_id'],
                'user_name' => isset($row['user_name']) ? (string) $row['user_name'] : null,
            ],
            $rows
        );
    }

    private function assertValidDate(string $value): string
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid date filter value: %s', $value));
        }

        return $value;
    }
}
