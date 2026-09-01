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
 * Persists MCP credential lifecycle records in `#__mcpserver_credential`.
 *
 * Never receives or returns a plaintext bearer token; only the verifier and
 * the already-encrypted API token are written, and listings never surface
 * either secret field.
 */
final class JoomlaCredentialLifecycleStore implements CredentialLifecycleStoreInterface
{
    private const TABLE = '#__mcpserver_credential';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_REVOKED = 'revoked';

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function save(array $record): string
    {
        $db = $this->db;
        $encryptedToken = $record['encrypted_token'];

        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName([
                'selector',
                'user_id',
                'name',
                'verifier',
                'token_ciphertext',
                'token_nonce',
                'token_tag',
                'key_version',
                'status',
                'created',
                'expires',
            ]))
            ->values(implode(',', [
                $db->quote((string) $record['selector']),
                (int) $record['owner_id'],
                $db->quote((string) $record['owner_name']),
                $db->quote((string) $record['verifier']),
                $db->quote((string) $encryptedToken['ciphertext']),
                $db->quote((string) $encryptedToken['nonce']),
                $db->quote((string) $encryptedToken['tag']),
                (int) $encryptedToken['key_version'],
                $db->quote(self::STATUS_ACTIVE),
                $db->quote(self::toUtcDateTimeString((int) $record['created_at'])),
                $db->quote(self::toUtcDateTimeString((int) $record['expires_at'])),
            ]));

        $db->setQuery($query)->execute();

        return (string) $db->insertid();
    }

    public function listByOwner(int $ownerId): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'id',
                'user_id',
                'name',
                'selector',
                'expires',
                'created',
                'status',
            ]))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('user_id') . ' = ' . (int) $ownerId);

        $rows = $db->setQuery($query)->loadAssocList() ?? [];

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'owner_id' => (int) $row['user_id'],
                'owner_name' => (string) $row['name'],
                'selector' => (string) $row['selector'],
                'expires_at' => self::toUnixTimestamp($row['expires']),
                'created_at' => self::toUnixTimestamp($row['created']),
                'revoked' => $row['status'] === self::STATUS_REVOKED,
            ],
            $rows
        );
    }

    public function findOwnership(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'user_id', 'status']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = ' . (int) $id);

        $row = $db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'owner_id' => (int) $row['user_id'],
            'revoked' => $row['status'] === self::STATUS_REVOKED,
        ];
    }

    public function revoke(string $id): void
    {
        if (!ctype_digit($id)) {
            return;
        }

        $db = $this->db;
        $now = self::toUtcDateTimeString(time());
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set([
                $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_REVOKED),
                $db->quoteName('revoked') . ' = ' . $db->quote($now),
            ])
            ->where($db->quoteName('id') . ' = ' . (int) $id)
            ->where($db->quoteName('status') . ' != ' . $db->quote(self::STATUS_REVOKED));

        $db->setQuery($query)->execute();
    }

    public function deleteRevoked(string $id): void
    {
        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException('Credential id must be numeric');
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = ' . (int) $id)
            ->where($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_REVOKED));

        $db->setQuery($query)->execute();
    }

    public function replace(array $record, string $revokedId): string
    {
        $db = $this->db;
        $supportsTransaction = method_exists($db, 'transactionStart');

        if ($supportsTransaction) {
            $db->transactionStart();
        }

        try {
            $id = $this->save($record);
            $this->revoke($revokedId);
        } catch (\Throwable $exception) {
            if ($supportsTransaction) {
                $db->transactionRollback();
            }

            throw $exception;
        }

        if ($supportsTransaction) {
            $db->transactionCommit();
        }

        return $id;
    }

    private static function toUtcDateTimeString(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private static function toUnixTimestamp(mixed $value): int
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return 0;
        }

        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->getTimestamp();
    }
}
