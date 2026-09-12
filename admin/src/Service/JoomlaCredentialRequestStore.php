<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\Database\DatabaseInterface;

final class JoomlaCredentialRequestStore implements CredentialRequestStoreInterface
{
    private const REQUEST_TABLE = '#__mcpserver_credential_request';
    private const EVENT_TABLE = '#__mcpserver_request_event';
    private const CREDENTIAL_TABLE = '#__mcpserver_credential';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function create(int $userId, string $clientName, int $requestedAt): string
    {
        return $this->inTransaction(function () use ($userId, $clientName, $requestedAt): string {
            $db = $this->db;
            $query = $db->getQuery(true)->insert($db->quoteName(self::REQUEST_TABLE))
                ->columns($db->quoteName(['user_id', 'client_name', 'status', 'requested']))
                ->values(implode(',', [$userId, $db->quote($clientName), $db->quote('requested'), $db->quote(self::utc($requestedAt))]));
            $db->setQuery($query)->execute();
            $id = (string) $db->insertid();
            $this->insertEvent($id, 'requested', $userId, $requestedAt);

            return $id;
        });
    }

    public function find(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        $db = $this->db;
        $query = $db->getQuery(true)->select($db->quoteName(['id', 'user_id', 'client_name', 'status', 'credential_expires', 'credential_id']))
            ->from($db->quoteName(self::REQUEST_TABLE))->where($db->quoteName('id') . ' = ' . (int) $id);
        $row = $db->setQuery($query)->loadAssoc();
        if ($row === null) {
            return null;
        }

        return self::metadata($row);
    }

    public function listForUser(int $userId): array
    {
        return $this->listByCondition($this->db->quoteName('user_id') . ' = ' . $userId);
    }

    public function listPending(): array
    {
        return $this->listByCondition($this->db->quoteName('status') . ' = ' . $this->db->quote('requested'));
    }

    public function decide(string $id, string $status, int $actorId, ?int $expiresAt, int $decidedAt): void
    {
        $this->inTransaction(function () use ($id, $status, $actorId, $expiresAt, $decidedAt): void {
            $db = $this->db;
            $sets = [
                $db->quoteName('status') . ' = ' . $db->quote($status),
                $db->quoteName('decided') . ' = ' . $db->quote(self::utc($decidedAt)),
                $db->quoteName('decided_by') . ' = ' . $actorId,
                $db->quoteName('credential_expires') . ' = ' . ($expiresAt === null ? 'NULL' : $db->quote(self::utc($expiresAt))),
            ];
            $query = $db->getQuery(true)->update($db->quoteName(self::REQUEST_TABLE))->set($sets)
                ->where($db->quoteName('id') . ' = ' . (int) $id)
                ->where($db->quoteName('status') . ' = ' . $db->quote('requested'));
            $db->setQuery($query)->execute();
            $this->requireAffectedRow();
            $this->insertEvent($id, $status, $actorId, $decidedAt);
        });
    }

    public function claimWithCredential(string $id, array $credential, int $claimedAt): string
    {
        return $this->inTransaction(function () use ($id, $credential, $claimedAt): string {
            $credentialId = $this->insertCredential($credential);
            $db = $this->db;
            $query = $db->getQuery(true)->update($db->quoteName(self::REQUEST_TABLE))
                ->set([
                    $db->quoteName('status') . ' = ' . $db->quote('claimed'),
                    $db->quoteName('claimed') . ' = ' . $db->quote(self::utc($claimedAt)),
                    $db->quoteName('credential_id') . ' = ' . (int) $credentialId,
                ])
                ->where($db->quoteName('id') . ' = ' . (int) $id)
                ->where($db->quoteName('status') . ' = ' . $db->quote('approved'))
                ->where($db->quoteName('credential_expires') . ' > ' . $db->quote(self::utc($claimedAt)));
            $db->setQuery($query)->execute();
            $this->requireAffectedRow();
            $this->insertEvent($id, 'claimed', (int) $credential['owner_id'], $claimedAt);

            return $credentialId;
        });
    }

    private function insertCredential(array $credential): string
    {
        $db = $this->db;
        $token = $credential['encrypted_token'];
        $query = $db->getQuery(true)->insert($db->quoteName(self::CREDENTIAL_TABLE))
            ->columns($db->quoteName(['selector', 'user_id', 'name', 'verifier', 'token_ciphertext', 'token_nonce', 'token_tag', 'key_version', 'status', 'created', 'expires']))
            ->values(implode(',', [$db->quote($credential['selector']), $credential['owner_id'], $db->quote($credential['owner_name']), $db->quote($credential['verifier']), $db->quote($token['ciphertext']), $db->quote($token['nonce']), $db->quote($token['tag']), $token['key_version'], $db->quote('active'), $db->quote(self::utc($credential['created_at'])), $db->quote(self::utc($credential['expires_at']))]));
        $db->setQuery($query)->execute();

        return (string) $db->insertid();
    }

    private function insertEvent(string $requestId, string $event, ?int $actorId, int $createdAt): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)->insert($db->quoteName(self::EVENT_TABLE))
            ->columns($db->quoteName(['request_id', 'event', 'actor_id', 'created']))
            ->values(implode(',', [(int) $requestId, $db->quote($event), $actorId === null ? 'NULL' : $actorId, $db->quote(self::utc($createdAt))]));
        $db->setQuery($query)->execute();
    }

    private function requireAffectedRow(): void
    {
        if (!method_exists($this->db, 'getAffectedRows') || $this->db->getAffectedRows() !== 1) {
            throw new \RuntimeException('Credential request was already transitioned');
        }
    }

    private function inTransaction(callable $operation): mixed
    {
        if (!method_exists($this->db, 'transactionStart')) {
            throw new \RuntimeException('Credential request transitions require database transactions');
        }
        $this->db->transactionStart();
        try {
            $result = $operation();
            $this->db->transactionCommit();
            return $result;
        } catch (\Throwable $exception) {
            $this->db->transactionRollback();
            throw $exception;
        }
    }

    /** @return list<array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string}> */
    private function listByCondition(string $condition): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'user_id', 'client_name', 'status', 'credential_expires', 'credential_id']))
            ->from($db->quoteName(self::REQUEST_TABLE))
            ->where($condition);

        return array_map(self::metadata(...), $db->setQuery($query)->loadAssocList() ?? []);
    }

    /** @return array{id:string,user_id:int,client_name:string,status:string,credential_expires:int,credential_id:?string} */
    private static function metadata(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'user_id' => (int) $row['user_id'], 'client_name' => (string) $row['client_name'],
            'status' => (string) $row['status'], 'credential_expires' => self::timestamp($row['credential_expires']),
            'credential_id' => $row['credential_id'] === null ? null : (string) $row['credential_id'],
        ];
    }

    private static function utc(int $timestamp): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    private static function timestamp(mixed $value): int { return $value === null || $value === '' ? 0 : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->getTimestamp(); }
}
