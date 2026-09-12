<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

/**
 * Records one row per MCP request into #__mcpserver_request_log and exposes
 * aggregate queries for the admin metrics dashboard.
 *
 * Writes are resilient: a failure to record a metric must never break the
 * request it is measuring, so record() swallows every throwable. Read methods
 * likewise return safe zero/empty defaults if the table is missing (e.g. on a
 * half-upgraded site) so the dashboard always renders.
 */
class MetricsService
{
    private const TABLE = '#__mcpserver_request_log';

    /**
     * Valid status values stored in the `status` column.
     */
    private const STATUSES = ['ok', 'error', 'blocked', 'auth_failed', 'rate_limited', 'invalid_request'];

    private Registry $params;

    public function __construct(Registry $params)
    {
        $this->params = $params;
    }

    /**
     * Whether request recording is enabled in the component options.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->params->get('metrics_enabled', 1);
    }

    /**
     * Retention window in days for the request log.
     */
    private function retentionDays(): int
    {
        return max(1, (int) $this->params->get('metrics_retention_days', 360));
    }

    /**
     * Record a single request. Silently no-ops when metrics are disabled and
     * never throws to the caller.
     *
     * @param  array  $data  Loose associative array; keys are whitelisted here.
     */
    public function record(array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $status = (string) ($data['status'] ?? '');
            if (!in_array($status, self::STATUSES, true)) {
                $status = '';
            }

            $row = (object) [
                'created'     => (string) ($data['created'] ?? Factory::getDate()->toSql()),
                'method'      => substr((string) ($data['method'] ?? ''), 0, 64),
                'tool_name'   => substr((string) ($data['tool_name'] ?? ''), 0, 128),
                'status'      => $status,
                'error_code'  => isset($data['error_code']) ? (int) $data['error_code'] : null,
                'http_status' => (int) ($data['http_status'] ?? 0),
                'duration_ms' => max(0, (int) ($data['duration_ms'] ?? 0)),
                'client_ip'   => substr((string) ($data['client_ip'] ?? ''), 0, 45),
                'context'     => substr((string) ($data['context'] ?? ''), 0, 10),
            ];

            $db = Factory::getDbo();
            $db->insertObject(self::TABLE, $row);

            // Opportunistic pruning (~1% of writes) keeps the table bounded
            // without a cron dependency.
            if (random_int(1, 100) === 1) {
                $this->prune();
            }
        } catch (\Throwable $e) {
            // Metrics must never break the request being measured.
        }
    }

    /**
     * Headline counters for the dashboard summary cards.
     *
     * @return array{total:int,last_24h:int,last_7d:int,error_rate:float,avg_latency_ms:float,rate_limited:int,auth_failed:int}
     */
    public function getSummary(): array
    {
        $defaults = [
            'total'          => 0,
            'last_24h'       => 0,
            'last_7d'        => 0,
            'error_rate'     => 0.0,
            'avg_latency_ms' => 0.0,
            'rate_limited'   => 0,
            'auth_failed'    => 0,
        ];

        try {
            $db    = Factory::getDbo();
            $table = $db->quoteName(self::TABLE);
            $day   = $db->quote(Factory::getDate('now')->modify('-1 day')->toSql());
            $week  = $db->quote(Factory::getDate('now')->modify('-7 day')->toSql());

            $query = $db->getQuery(true)
                ->select([
                    'COUNT(*) AS total',
                    'SUM(CASE WHEN ' . $db->quoteName('created') . ' >= ' . $day . ' THEN 1 ELSE 0 END) AS last_24h',
                    'SUM(CASE WHEN ' . $db->quoteName('created') . ' >= ' . $week . ' THEN 1 ELSE 0 END) AS last_7d',
                    'SUM(CASE WHEN ' . $db->quoteName('status') . ' = ' . $db->quote('error') . ' THEN 1 ELSE 0 END) AS errors',
                    'SUM(CASE WHEN ' . $db->quoteName('status') . ' = ' . $db->quote('rate_limited') . ' THEN 1 ELSE 0 END) AS rate_limited',
                    'SUM(CASE WHEN ' . $db->quoteName('status') . ' = ' . $db->quote('auth_failed') . ' THEN 1 ELSE 0 END) AS auth_failed',
                    'AVG(' . $db->quoteName('duration_ms') . ') AS avg_latency_ms',
                ])
                ->from($table);

            $row = $db->setQuery($query)->loadAssoc();

            if (!$row) {
                return $defaults;
            }

            $total  = (int) $row['total'];
            $errors = (int) $row['errors'];

            return [
                'total'          => $total,
                'last_24h'       => (int) $row['last_24h'],
                'last_7d'        => (int) $row['last_7d'],
                'error_rate'     => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
                'avg_latency_ms' => round((float) $row['avg_latency_ms'], 1),
                'rate_limited'   => (int) $row['rate_limited'],
                'auth_failed'    => (int) $row['auth_failed'],
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Most-called tools (excludes rows without a tool, e.g. auth/rate-limit).
     *
     * @return array<int,array{tool_name:string,count:int}>
     */
    public function getTopTools(int $limit = 10): array
    {
        return $this->topBy('tool_name', $limit);
    }

    /**
     * Most-used RPC methods (excludes rows recorded before parsing).
     *
     * @return array<int,array{method:string,count:int}>
     */
    public function getTopMethods(int $limit = 10): array
    {
        return $this->topBy('method', $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function topBy(string $column, int $limit): array
    {
        try {
            $db   = Factory::getDbo();
            $col  = $db->quoteName($column);
            $query = $db->getQuery(true)
                ->select([$col, 'COUNT(*) AS count'])
                ->from($db->quoteName(self::TABLE))
                ->where($col . ' <> ' . $db->quote(''))
                ->group($col)
                ->order('count DESC');

            $db->setQuery($query, 0, max(1, $limit));
            $rows = $db->loadAssocList();

            return array_map(static function (array $row) use ($column): array {
                return [
                    $column => (string) $row[$column],
                    'count' => (int) $row['count'],
                ];
            }, $rows ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Daily request counts for the last $days days, with gaps filled so the
     * chart has a continuous axis.
     *
     * @return array<int,array{day:string,count:int,errors:int}>
     */
    public function getRequestsPerDay(int $days = 14): array
    {
        $days = max(1, $days);

        // Build the continuous day axis first (oldest -> today).
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Factory::getDate('now')->modify('-' . $i . ' day')->format('Y-m-d');
            $series[$day] = ['day' => $day, 'count' => 0, 'errors' => 0];
        }

        try {
            $db    = Factory::getDbo();
            $since = $db->quote(Factory::getDate('now')->modify('-' . ($days - 1) . ' day')->format('Y-m-d') . ' 00:00:00');

            $query = $db->getQuery(true)
                ->select([
                    'DATE(' . $db->quoteName('created') . ') AS day',
                    'COUNT(*) AS count',
                    'SUM(CASE WHEN ' . $db->quoteName('status') . ' = ' . $db->quote('error') . ' THEN 1 ELSE 0 END) AS errors',
                ])
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('created') . ' >= ' . $since)
                ->group('DATE(' . $db->quoteName('created') . ')');

            $rows = $db->setQuery($query)->loadAssocList();

            foreach ($rows ?: [] as $row) {
                $day = (string) $row['day'];
                if (isset($series[$day])) {
                    $series[$day]['count']  = (int) $row['count'];
                    $series[$day]['errors'] = (int) $row['errors'];
                }
            }
        } catch (\Throwable $e) {
            // Fall through with the zero-filled axis.
        }

        return array_values($series);
    }

    /**
     * Most recent requests for the activity table.
     *
     * @return array<int,object>
     */
    public function getRecentRequests(int $limit = 25): array
    {
        try {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::TABLE))
                ->order($db->quoteName('id') . ' DESC');

            $db->setQuery($query, 0, max(1, $limit));

            return $db->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Delete rows older than the configured retention window.
     *
     * @return int  Number of rows deleted.
     */
    public function prune(): int
    {
        try {
            $db        = Factory::getDbo();
            $threshold = $db->quote(Factory::getDate('now')->modify('-' . $this->retentionDays() . ' day')->toSql());

            $query = $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('created') . ' < ' . $threshold);

            $db->setQuery($query)->execute();

            return (int) $db->getAffectedRows();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
