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
 * Gives a user a Joomla API token seed when they have none, exactly as
 * plg_user_token does when it saves a profile: a joomlatoken.token row holding
 * 32 random bytes in base64, and a joomlatoken.enabled row set to 1.
 *
 * It never widens Joomla's own policy. A user outside the plugin's Allowed
 * User Groups, or on a site where the plugin is disabled, gets nothing: Joomla
 * would refuse their token anyway. A token the user switched off is never
 * switched back on.
 */
final class JoomlaApiTokenProvisioner
{
    public const OK = 'ok';
    public const REASON_GROUP_NOT_ALLOWED = 'group_not_allowed';
    public const REASON_PLUGIN_DISABLED = 'plugin_disabled';
    public const REASON_NOT_ENABLED = JoomlaApiTokenOwnershipValidator::REASON_NOT_ENABLED;

    /** Matches plg_user_token's $tokenLength. */
    private const SEED_BYTES = 32;
    private const PROFILE_PREFIX = 'joomlatoken.';

    /** @var callable(int): string */
    private $randomBytes;

    /** @param callable(int): string $randomBytes Cryptographically secure random bytes of the given length. */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly JoomlaApiTokenAvailability $availability,
        callable $randomBytes,
    ) {
        $this->randomBytes = $randomBytes;
    }

    /**
     * Make sure the user has a token seed, creating one if Joomla would let
     * them hold a token.
     *
     * Returns OK when the user has a seed afterwards (including one someone
     * else created meanwhile), or why none was created. Database failures,
     * including a failed policy lookup, throw: this method never guesses.
     *
     * @return self::OK|self::REASON_*
     */
    public function provision(int $userId): string
    {
        $policy = $this->availability->assess($userId);
        if (!$policy['plugin_enabled']) {
            return self::REASON_PLUGIN_DISABLED;
        }
        if (!$policy['can_create']) {
            return self::REASON_GROUP_NOT_ALLOWED;
        }

        $db = $this->db;
        $transactional = method_exists($db, 'transactionStart');
        if ($transactional) {
            $db->transactionStart();
        }

        try {
            $result = $this->writeSeedIfMissing($userId);
            if ($transactional) {
                $db->transactionCommit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($transactional) {
                $db->transactionRollback();
            }
            throw $e;
        }
    }

    /** @return self::OK|self::REASON_NOT_ENABLED */
    private function writeSeedIfMissing(int $userId): string
    {
        // Re-read inside the transaction: another claim may have created one.
        $seed = $this->profileValue($userId, 'token');
        if ($seed !== null && $seed !== '') {
            return self::OK;
        }
        if ($this->profileValue($userId, 'enabled') === '0') {
            return self::REASON_NOT_ENABLED;
        }

        $db = $this->db;
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote(self::PROFILE_PREFIX . '%'))
        )->execute();

        $rows = [
            'token' => base64_encode((string) ($this->randomBytes)(self::SEED_BYTES)),
            'enabled' => '1',
        ];
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__user_profiles'))
            ->columns($db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']));
        $ordering = 1;
        foreach ($rows as $key => $value) {
            $query->values($userId . ', ' . $db->quote(self::PROFILE_PREFIX . $key) . ', ' . $db->quote($value) . ', ' . $ordering++);
        }
        $db->setQuery($query)->execute();

        return self::OK;
    }

    private function profileValue(int $userId, string $key): ?string
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('profile_key') . ' = ' . $db->quote(self::PROFILE_PREFIX . $key))
            ->where($db->quoteName('user_id') . ' = ' . $userId);

        $value = $db->setQuery($query)->loadResult();

        return is_string($value) ? $value : null;
    }
}
