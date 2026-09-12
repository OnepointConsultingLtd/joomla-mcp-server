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
 * Verifies that a submitted token is the current user's enabled Joomla API token.
 *
 * Joomla API tokens encode `sha256:userId:hmac` as strict base64. The HMAC is
 * calculated from the user's `joomlatoken.token` seed and the site secret.
 */
final class JoomlaApiTokenOwnershipValidator
{
    private const TOKEN_PROFILE_KEY = 'joomlatoken.token';
    private const ENABLED_PROFILE_KEY = 'joomlatoken.enabled';
    private const ALGORITHM = 'sha256';

    /** @param callable(): string $secretProvider */
    public function __construct(
        private readonly DatabaseInterface $db,
        private $secretProvider,
    ) {
    }

    public function belongsToUser(string $token, int $userId): bool
    {
        if ($userId <= 0 || $token === '') {
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3 || $parts[0] !== self::ALGORITHM || !ctype_digit($parts[1])) {
            return false;
        }

        if ((int) $parts[1] !== $userId || $parts[2] === '') {
            return false;
        }

        try {
            $seed = $this->profileValue($userId, self::TOKEN_PROFILE_KEY);
            $enabled = $this->profileValue($userId, self::ENABLED_PROFILE_KEY);
            $rawSeed = $seed === null ? false : base64_decode($seed, true);
            $secret = (string) ($this->secretProvider)();

            if ($rawSeed === false || $rawSeed === '' || $enabled !== '1' || $secret === '') {
                return false;
            }

            $expected = hash_hmac(self::ALGORITHM, $rawSeed, $secret);

            return hash_equals($expected, $parts[2]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function profileValue(int $userId, string $profileKey): ?string
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('profile_key') . ' = ' . $db->quote($profileKey))
            ->where($db->quoteName('user_id') . ' = ' . $userId);

        $value = $db->setQuery($query)->loadResult();

        return is_string($value) ? $value : null;
    }
}
