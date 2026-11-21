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
use DateTimeZone;
use Joomla\Database\DatabaseInterface;

/**
 * OAuthStoreInterface over the #__mcpserver_oauth_* tables. All times are UTC.
 */
final class JoomlaOAuthStore implements OAuthStoreInterface
{
    private const CLIENT = '#__mcpserver_oauth_client';
    private const GRANT = '#__mcpserver_oauth_grant';
    private const CODE = '#__mcpserver_oauth_code';
    private const TOKEN = '#__mcpserver_oauth_token';
    private const CREDENTIAL = '#__mcpserver_credential';

    /**
     * How long a registered client that never completed an authorisation is
     * kept. Generous, because clients cache their registration: one removed
     * too early meets a dead-end "unknown client" page it cannot recover from.
     */
    private const UNUSED_CLIENT_LIFETIME = '-30 days';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function createClient(OAuthClient $client): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::CLIENT))
            ->columns($db->quoteName(['client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method', 'secret_hash', 'created']))
            ->values(implode(',', [
                $db->quote($client->clientId),
                $db->quote($client->clientName),
                $db->quote((string) json_encode($client->redirectUris, JSON_UNESCAPED_SLASHES)),
                $db->quote($client->tokenEndpointAuthMethod),
                $client->secretHash === null ? 'NULL' : $db->quote($client->secretHash),
                $db->quote(self::format($client->created)),
            ]));

        $db->setQuery($query)->execute();
    }

    public function replaceClient(OAuthClient $client): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::CLIENT))
            ->set([
                $db->quoteName('client_name') . ' = ' . $db->quote($client->clientName),
                $db->quoteName('redirect_uris') . ' = ' . $db->quote((string) json_encode($client->redirectUris, JSON_UNESCAPED_SLASHES)),
                $db->quoteName('token_endpoint_auth_method') . ' = ' . $db->quote($client->tokenEndpointAuthMethod),
                $db->quoteName('created') . ' = ' . $db->quote(self::format($client->created)),
            ])
            ->where($db->quoteName('client_id') . ' = ' . $db->quote($client->clientId));

        $db->setQuery($query)->execute();
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method', 'secret_hash', 'created']))
            ->from($db->quoteName(self::CLIENT))
            ->where($db->quoteName('client_id') . ' = ' . $db->quote($clientId));

        $row = $db->setQuery($query)->loadAssoc();
        if ($row === null) {
            return null;
        }

        $uris = json_decode((string) $row['redirect_uris'], true);

        return new OAuthClient(
            (string) $row['client_id'],
            (string) $row['client_name'],
            is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
            (string) $row['token_endpoint_auth_method'],
            $row['secret_hash'] === null ? null : (string) $row['secret_hash'],
            self::parse($row['created']) ?? new DateTimeImmutable('@0'),
        );
    }

    public function touchClient(string $clientId, DateTimeImmutable $now): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::CLIENT))
            ->set($db->quoteName('last_used') . ' = ' . $db->quote(self::format($now)))
            ->where($db->quoteName('client_id') . ' = ' . $db->quote($clientId));

        $db->setQuery($query)->execute();
    }

    public function createGrant(string $clientId, int $userId, int $credentialId, string $scope, string $resource, DateTimeImmutable $now): int
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::GRANT))
            ->columns($db->quoteName(['client_id', 'user_id', 'credential_id', 'scope', 'resource', 'created']))
            ->values(implode(',', [
                $db->quote($clientId),
                (int) $userId,
                (int) $credentialId,
                $db->quote($scope),
                $db->quote($resource),
                $db->quote(self::format($now)),
            ]));

        $db->setQuery($query)->execute();

        return (int) $db->insertid();
    }

    public function revokeGrant(int $grantId, DateTimeImmutable $now): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::GRANT))
            ->set($db->quoteName('revoked') . ' = ' . $db->quote(self::format($now)))
            ->where($db->quoteName('id') . ' = ' . (int) $grantId)
            ->where($db->quoteName('revoked') . ' IS NULL');

        $db->setQuery($query)->execute();
    }

    public function createCode(int $grantId, string $codeHash, string $redirectUri, string $codeChallenge, DateTimeImmutable $expires): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::CODE))
            ->columns($db->quoteName(['code_hash', 'grant_id', 'redirect_uri', 'code_challenge', 'expires']))
            ->values(implode(',', [
                $db->quote($codeHash),
                (int) $grantId,
                $db->quote($redirectUri),
                $db->quote($codeChallenge),
                $db->quote(self::format($expires)),
            ]));

        $db->setQuery($query)->execute();
    }

    public function consumeCode(string $codeHash, DateTimeImmutable $now): ?OAuthCodeRecord
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('c.id'),
                $db->quoteName('c.grant_id'),
                $db->quoteName('c.redirect_uri'),
                $db->quoteName('c.code_challenge'),
                $db->quoteName('c.expires'),
                $db->quoteName('g.client_id'),
                $db->quoteName('g.scope'),
                $db->quoteName('g.resource'),
            ])
            ->from($db->quoteName(self::CODE, 'c'))
            ->join('INNER', $db->quoteName(self::GRANT, 'g') . ' ON ' . $db->quoteName('g.id') . ' = ' . $db->quoteName('c.grant_id'))
            ->where($db->quoteName('c.code_hash') . ' = ' . $db->quote($codeHash));

        $row = $db->setQuery($query)->loadAssoc();
        if ($row === null) {
            return null;
        }

        // The conditional UPDATE is the atomic step: of two concurrent
        // redemptions only one changes the row.
        $mark = $db->getQuery(true)
            ->update($db->quoteName(self::CODE))
            ->set($db->quoteName('used') . ' = ' . $db->quote(self::format($now)))
            ->where($db->quoteName('id') . ' = ' . (int) $row['id'])
            ->where($db->quoteName('used') . ' IS NULL');
        $db->setQuery($mark)->execute();

        return new OAuthCodeRecord(
            (int) $row['grant_id'],
            (string) $row['client_id'],
            (string) $row['redirect_uri'],
            (string) $row['code_challenge'],
            (string) $row['scope'],
            (string) $row['resource'],
            self::parse($row['expires']) ?? new DateTimeImmutable('@0'),
            $db->getAffectedRows() !== 1,
        );
    }

    public function createToken(int $grantId, string $type, string $tokenHash, DateTimeImmutable $expires, DateTimeImmutable $now): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::TOKEN))
            ->columns($db->quoteName(['token_hash', 'grant_id', 'type', 'created', 'expires']))
            ->values(implode(',', [
                $db->quote($tokenHash),
                (int) $grantId,
                $db->quote($type),
                $db->quote(self::format($now)),
                $db->quote(self::format($expires)),
            ]));

        $db->setQuery($query)->execute();
    }

    public function findToken(string $tokenHash): ?OAuthGrantTokenRecord
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('t.id'),
                $db->quoteName('t.type'),
                $db->quoteName('t.grant_id'),
                $db->quoteName('t.expires'),
                $db->quoteName('t.revoked'),
                $db->quoteName('g.client_id'),
                $db->quoteName('g.user_id'),
                $db->quoteName('g.credential_id'),
                $db->quoteName('g.scope'),
                $db->quoteName('g.resource'),
                $db->quoteName('g.revoked', 'grant_revoked'),
                $db->quoteName('cr.selector'),
            ])
            ->from($db->quoteName(self::TOKEN, 't'))
            ->join('INNER', $db->quoteName(self::GRANT, 'g') . ' ON ' . $db->quoteName('g.id') . ' = ' . $db->quoteName('t.grant_id'))
            // INNER: a grant whose credential has been deleted authenticates nothing.
            ->join('INNER', $db->quoteName(self::CREDENTIAL, 'cr') . ' ON ' . $db->quoteName('cr.id') . ' = ' . $db->quoteName('g.credential_id'))
            ->where($db->quoteName('t.token_hash') . ' = ' . $db->quote($tokenHash));

        $row = $db->setQuery($query)->loadAssoc();
        if ($row === null) {
            return null;
        }

        return new OAuthGrantTokenRecord(
            (int) $row['id'],
            (string) $row['type'],
            (int) $row['grant_id'],
            (string) $row['client_id'],
            (int) $row['user_id'],
            (int) $row['credential_id'],
            (string) $row['selector'],
            (string) $row['scope'],
            (string) $row['resource'],
            self::parse($row['expires']) ?? new DateTimeImmutable('@0'),
            self::parse($row['revoked']),
            self::parse($row['grant_revoked']),
        );
    }

    public function revokeToken(int $tokenId, DateTimeImmutable $now): bool
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TOKEN))
            ->set($db->quoteName('revoked') . ' = ' . $db->quote(self::format($now)))
            ->where($db->quoteName('id') . ' = ' . (int) $tokenId)
            ->where($db->quoteName('revoked') . ' IS NULL');

        $db->setQuery($query)->execute();

        return $db->getAffectedRows() === 1;
    }

    public function usableCredentials(int $userId, DateTimeImmutable $now): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'expires']))
            ->from($db->quoteName(self::CREDENTIAL))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($db->quoteName('status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('revoked') . ' IS NULL')
            ->where('(' . $db->quoteName('expires') . ' IS NULL OR ' . $db->quoteName('expires') . ' > ' . $db->quote(self::format($now)) . ')')
            ->order($db->quoteName('name') . ' ASC');

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'expires' => self::parse($row['expires']),
            ],
            $db->setQuery($query)->loadAssocList() ?: []
        );
    }

    public function purge(DateTimeImmutable $now): void
    {
        $db = $this->db;
        $nowSql = $db->quote(self::format($now));

        // Expired codes and tokens are useless. Revoked refresh tokens are kept
        // until they expire, so a replay of one is still recognised as reuse.
        foreach ([self::CODE, self::TOKEN] as $table) {
            $db->setQuery(
                $db->getQuery(true)->delete($db->quoteName($table))->where($db->quoteName('expires') . ' < ' . $nowSql)
            )->execute();
        }

        $grants = $db->getQuery(true)
            ->select($db->quoteName('client_id'))
            ->from($db->quoteName(self::GRANT));

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::CLIENT))
                ->where($db->quoteName('last_used') . ' IS NULL')
                ->where($db->quoteName('created') . ' < ' . $db->quote(self::format($now->modify(self::UNUSED_CLIENT_LIFETIME))))
                ->where($db->quoteName('client_id') . ' NOT IN (' . $grants . ')')
        )->execute();
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
