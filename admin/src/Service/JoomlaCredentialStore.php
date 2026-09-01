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

final class JoomlaCredentialStore implements CredentialStoreInterface
{
    private const TABLE = '#__mcpserver_credential';

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function findBySelector(string $selector): ?CredentialRecord
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('selector'),
                $db->quoteName('user_id'),
                $db->quoteName('name'),
                $db->quoteName('verifier'),
                $db->quoteName('token_ciphertext'),
                $db->quoteName('token_nonce'),
                $db->quoteName('token_tag'),
                $db->quoteName('key_version'),
                $db->quoteName('status'),
                $db->quoteName('expires'),
                $db->quoteName('revoked'),
            ])
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('selector') . ' = ' . $db->quote($selector));

        $row = $db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        return new CredentialRecord(
            id: (int) $row['id'],
            selector: (string) $row['selector'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            verifier: (string) $row['verifier'],
            encryptedToken: [
                'ciphertext' => (string) $row['token_ciphertext'],
                'nonce' => (string) $row['token_nonce'],
                'tag' => (string) $row['token_tag'],
                'key_version' => (int) $row['key_version'],
            ],
            status: (string) $row['status'],
            expires: self::toUtcDateTime($row['expires']),
            revoked: self::toUtcDateTime($row['revoked']),
        );
    }

    public function touchLastUsed(int $credentialId): void
    {
        $db = $this->db;
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('last_used') . ' = ' . $db->quote($now))
            ->where($db->quoteName('id') . ' = ' . (int) $credentialId);

        $db->setQuery($query)->execute();
    }

    private static function toUtcDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
