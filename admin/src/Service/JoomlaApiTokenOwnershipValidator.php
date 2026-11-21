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
 * Works out a user's current Joomla API token from their account, and checks
 * whether a given token is that token.
 *
 * Joomla API tokens encode `algo:userId:hmac` as strict base64. The HMAC is
 * calculated from the user's `joomlatoken.token` seed and the site secret.
 * Claiming a credential uses currentToken(); check() is kept for diagnosing a
 * token someone holds.
 */
final class JoomlaApiTokenOwnershipValidator
{
    private const TOKEN_PROFILE_KEY = 'joomlatoken.token';
    private const ENABLED_PROFILE_KEY = 'joomlatoken.enabled';

    /**
     * Algorithms Joomla itself accepts, mirroring $allowedAlgos in
     * plugins/api-authentication/token. The algorithm is carried inside the
     * token string; plg_user_token reads it from its form file, so a site whose
     * token.xml sets algo="sha512" issues sha512 tokens. Hardcoding sha256 here
     * rejected those as "not yours", with no way to complete the flow.
     */
    private const ALLOWED_ALGORITHMS = ['sha256', 'sha512'];

    /** Reason codes returned by check(). */
    public const OK = 'ok';
    public const REASON_MALFORMED = 'malformed';
    public const REASON_ALGORITHM = 'algorithm';
    public const REASON_WRONG_USER = 'wrong_user';
    public const REASON_NOT_ENABLED = 'not_enabled';
    public const REASON_NO_SEED = 'no_seed';
    public const REASON_NO_SECRET = 'no_secret';
    public const REASON_MISMATCH = 'mismatch';

    /** @var callable(): string */
    private $algorithmProvider;

    /**
     * @param callable(): string      $secretProvider
     * @param (callable(): string)|null $algorithmProvider The algorithm this site
     *                                  issues tokens with; see algorithmFromFormFile().
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private $secretProvider,
        ?callable $algorithmProvider = null,
    ) {
        $this->algorithmProvider = $algorithmProvider ?? static fn (): string => 'sha256';
    }

    /**
     * The algorithm plg_user_token issues tokens with, read the way that
     * plugin's own getAlgorithmFromFormFile() reads it: the algo attribute in
     * its forms/token.xml, falling back to sha256.
     */
    public static function algorithmFromFormFile(string $file): string
    {
        $contents = is_file($file) ? @file_get_contents($file) : false;

        if ($contents === false || preg_match('/\s*algo=\s*"\s*([a-z0-9]+)\s*"/i', $contents, $matches) !== 1) {
            return 'sha256';
        }

        return $matches[1];
    }

    /**
     * The user's current Joomla API token as this site issues it, or why none
     * can be worked out.
     *
     * @return array{reason:self::OK,token:string}|array{reason:string,token:null}
     */
    public function currentToken(int $userId): array
    {
        return $this->deriveToken($userId, (string) ($this->algorithmProvider)());
    }

    public function belongsToUser(string $token, int $userId): bool
    {
        return $this->check($token, $userId) === self::OK;
    }

    /**
     * Why a token was rejected.
     *
     * Every branch used to return a bare false, so "you pasted somebody else's
     * token", "your API token is not enabled", and "this site issues sha512"
     * were indistinguishable to the person trying to claim a credential. The
     * caller maps these to specific, actionable messages.
     *
     * The reasons are deliberately about the claiming user's own account, so
     * they disclose nothing about anyone else — REASON_WRONG_USER never says
     * whose token it is.
     */
    public function check(string $token, int $userId): string
    {
        if ($userId <= 0 || trim($token) === '') {
            return self::REASON_MALFORMED;
        }

        $decoded = base64_decode(trim($token), true);
        if ($decoded === false || $decoded === '') {
            return self::REASON_MALFORMED;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[1]) || $parts[2] === '') {
            return self::REASON_MALFORMED;
        }

        if (!in_array($parts[0], self::ALLOWED_ALGORITHMS, true)) {
            return self::REASON_ALGORITHM;
        }

        if ((int) $parts[1] !== $userId) {
            return self::REASON_WRONG_USER;
        }

        try {
            // Derive with the algorithm the token declares, not a fixed one.
            $derived = $this->deriveToken($userId, $parts[0]);
        } catch (\Throwable) {
            return self::REASON_MISMATCH;
        }

        if ($derived['token'] === null) {
            return $derived['reason'];
        }

        // Compare only the HMAC, as before: the user id has already been
        // matched numerically, so "042" and "42" are the same user.
        $expectedHmac = explode(':', (string) base64_decode($derived['token'], true), 3)[2] ?? '';

        return hash_equals($expectedHmac, $parts[2]) ? self::OK : self::REASON_MISMATCH;
    }

    /**
     * The user's current Joomla API token, worked out the way plg_user_token
     * builds the one it displays: base64("algo:userId:hmac(algo, seed, secret)").
     *
     * Returns the token, or why none can be worked out. Database failures are
     * not swallowed here: a caller that is about to store the token must not
     * mistake an outage for an account problem.
     *
     * @return array{reason:self::OK,token:string}|array{reason:string,token:null}
     */
    public function deriveToken(int $userId, string $algorithm): array
    {
        if (!in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
            return ['reason' => self::REASON_ALGORITHM, 'token' => null];
        }

        $seed = $this->profileValue($userId, self::TOKEN_PROFILE_KEY);
        $enabled = $this->profileValue($userId, self::ENABLED_PROFILE_KEY);
        $rawSeed = $seed === null ? false : base64_decode($seed, true);

        if ($rawSeed === false || $rawSeed === '') {
            return ['reason' => self::REASON_NO_SEED, 'token' => null];
        }

        if ($enabled !== '1') {
            return ['reason' => self::REASON_NOT_ENABLED, 'token' => null];
        }

        $secret = (string) ($this->secretProvider)();
        if ($secret === '') {
            return ['reason' => self::REASON_NO_SECRET, 'token' => null];
        }

        return [
            'reason' => self::OK,
            'token' => base64_encode($algorithm . ':' . $userId . ':' . hash_hmac($algorithm, $rawSeed, $secret)),
        ];
    }

    /**
     * Whether this user currently has a usable Joomla API token, without
     * needing them to paste one first.
     *
     * Lets the claim UI say "you have no API token yet, and here is why" up
     * front, instead of only after a failed claim. The commonest cause is
     * plg_user_token's allowedUserGroups, which defaults to Super Users only,
     * so an ordinary user has no API Tokens tab on their account at all.
     *
     * @return self::OK|self::REASON_NO_SEED|self::REASON_NOT_ENABLED
     */
    public function tokenState(int $userId): string
    {
        if ($userId <= 0) {
            return self::REASON_NO_SEED;
        }

        try {
            $seed = $this->profileValue($userId, self::TOKEN_PROFILE_KEY);
            $rawSeed = $seed === null ? false : base64_decode($seed, true);

            if ($rawSeed === false || $rawSeed === '') {
                return self::REASON_NO_SEED;
            }

            return $this->profileValue($userId, self::ENABLED_PROFILE_KEY) === '1'
                ? self::OK
                : self::REASON_NOT_ENABLED;
        } catch (\Throwable) {
            // Never block the page on a diagnostic lookup; the claim itself
            // still validates properly.
            return self::OK;
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
