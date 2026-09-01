<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;

/**
 * Prunes old rows from #__mcpserver_request_log according to an
 * admin-configurable retention window. Only the request-log audit table
 * is ever targeted; credential and Joomla action-log rows are owned by
 * other components/services and are never touched here.
 */
final class GovernanceAuditRetentionService
{
    private const TABLE = '#__mcpserver_request_log';

    private const MIN_RETENTION_DAYS = 1;

    private const MAX_RETENTION_DAYS = 3650;

    /**
     * @param  callable(): DateTimeImmutable  $clock  Supplies the current UTC time.
     */
    public function __construct(
        private DatabaseInterface $db,
        private $clock,
    ) {
    }

    /**
     * Deletes rows from #__mcpserver_request_log whose `created` timestamp
     * is strictly older than (now - $retentionDays), and returns the number
     * of rows deleted.
     *
     * @throws InvalidArgumentException  When $retentionDays is outside 1..3650.
     */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS || $retentionDays > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'retentionDays must be between %d and %d, got %d.',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS,
                $retentionDays,
            ));
        }

        $db = $this->db;

        $cutoff = ($this->clock)()->sub(new DateInterval("P{$retentionDays}D"))->format('Y-m-d H:i:s');

        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('created') . ' < ' . $db->quote($cutoff));

        $db->setQuery($query)->execute();

        return $db->getAffectedRows();
    }
}
