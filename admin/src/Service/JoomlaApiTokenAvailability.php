<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Whether a given user is even able to create a Joomla API token.
 *
 * Claiming a governed credential requires the requester's own API token, but
 * Joomla's "User - Joomla API Token" plugin gates the API Tokens tab on its
 * allowedUserGroups setting, which ships as [8] — Super Users only. A requester
 * outside those groups has no tab, cannot produce a token, and cannot complete
 * a claim; only a Super User can fix that, so the approval queue is where it
 * needs to be reported.
 *
 * Mirrors plg_user_token's own isInAllowedUserGroup(), including the rule that
 * an *empty* allowedUserGroups means every group is permitted rather than none.
 */
final class JoomlaApiTokenAvailability
{
    private const DEFAULT_ALLOWED_GROUPS = [8];

    /** @var callable(int): list<int> */
    private $groupsForUser;

    /** @var array{enabled:bool,groups:list<int>,extension_id:?int}|null Cached plugin state for this request. */
    private ?array $plugin = null;

    /**
     * @param callable(int): list<int> $groupsForUser Authorised group ids for a user,
     *                                                inherited groups included.
     */
    public function __construct(private readonly DatabaseInterface $db, callable $groupsForUser)
    {
        $this->groupsForUser = $groupsForUser;
    }

    /**
     * @return array{can_create:bool, plugin_enabled:bool, group_names:list<string>, extension_id:?int}
     */
    public function forUser(int $userId): array
    {
        $unknown = ['can_create' => true, 'plugin_enabled' => true, 'group_names' => [], 'extension_id' => null];

        if ($userId <= 0) {
            return $unknown;
        }

        try {
            $plugin = $this->pluginState();

            if (!$plugin['enabled']) {
                return [
                    'can_create' => false,
                    'plugin_enabled' => false,
                    'group_names' => $this->groupNames($this->userGroups($userId)),
                    'extension_id' => $plugin['extension_id'],
                ];
            }

            $userGroups = $this->userGroups($userId);

            // Empty allowedUserGroups means every group is permitted.
            $canCreate = $plugin['groups'] === []
                || array_intersect($userGroups, $plugin['groups']) !== [];

            return [
                'can_create' => $canCreate,
                'plugin_enabled' => true,
                'group_names' => $canCreate ? [] : $this->groupNames($userGroups),
                'extension_id' => $plugin['extension_id'],
            ];
        } catch (\Throwable) {
            // Diagnostics must never block the approval queue. Assume it is
            // fine; the claim itself still validates for real.
            return $unknown;
        }
    }

    /** @return array{enabled:bool,groups:list<int>,extension_id:?int} */
    private function pluginState(): array
    {
        if ($this->plugin !== null) {
            return $this->plugin;
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'enabled', 'params']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('user'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('token'));

        $row = $db->setQuery($query)->loadAssoc();

        if ($row === null) {
            // Plugin not installed at all: nothing to link to.
            return $this->plugin = [
                'enabled' => false,
                'groups' => self::DEFAULT_ALLOWED_GROUPS,
                'extension_id' => null,
            ];
        }

        $params = json_decode((string) ($row['params'] ?? ''), true);
        $groups = is_array($params) && array_key_exists('allowedUserGroups', $params)
            ? $params['allowedUserGroups']
            : self::DEFAULT_ALLOWED_GROUPS;

        if (!is_array($groups)) {
            $groups = $groups === null || $groups === '' ? [] : [$groups];
        }

        return $this->plugin = [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'groups' => array_values(array_map('intval', $groups)),
            'extension_id' => isset($row['extension_id']) ? (int) $row['extension_id'] : null,
        ];
    }

    /** @return list<int> */
    private function userGroups(int $userId): array
    {
        return array_values(array_map('intval', ($this->groupsForUser)($userId)));
    }

    /**
     * @param list<int> $groupIds
     * @return list<string>
     */
    private function groupNames(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $db = $this->db;
        $ids = implode(',', array_map('intval', $groupIds));
        $query = $db->getQuery(true)
            ->select($db->quoteName('title'))
            ->from($db->quoteName('#__usergroups'))
            ->where($db->quoteName('id') . ' IN (' . $ids . ')');

        $titles = $db->setQuery($query)->loadColumn();

        return is_array($titles) ? array_values(array_map('strval', $titles)) : [];
    }
}
