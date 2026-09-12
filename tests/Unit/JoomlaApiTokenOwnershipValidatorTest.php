<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenOwnershipValidator;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class ApiTokenOwnershipQuery implements QueryInterface
{
    public function select(array|string $columns): self { return $this; }
    public function from(array|string $tables): self { return $this; }
    public function where(array|string $conditions, string $glue = 'AND'): self { return $this; }
    public function update(string $table): self { return $this; }
    public function set(array|string $values): self { return $this; }
    public function insert(string $table): self { return $this; }
    public function columns(array|string $columns): self { return $this; }
    public function values(array|string $values): self { return $this; }
    public function __toString(): string { return ''; }
}

final class ApiTokenOwnershipDatabase implements DatabaseInterface
{
    /** @param list<string|null> $profileValues */
    public function __construct(private array $profileValues)
    {
    }

    public function quoteName(array|string $name, array|string|null $alias = null): array|string { return is_array($name) ? $name : $name; }
    public function quote(array|string $text, bool $escape = true): array|string { return $text; }
    public function getQuery(bool $new = false): QueryInterface|string { return new ApiTokenOwnershipQuery(); }
    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self { return $this; }
    public function loadAssoc(): ?array { return null; }
    public function loadResult(): mixed { return array_shift($this->profileValues); }
    public function execute(): bool { return true; }
}

final class JoomlaApiTokenOwnershipValidatorTest extends TestCase
{
    public function testAcceptsCurrentUsersOfficialSha256TokenWithEnabledSeed(): void
    {
        $seed = base64_encode('seed-material-for-user-42');
        $token = base64_encode('sha256:42:' . hash_hmac('sha256', base64_decode($seed, true), 'site-secret'));
        $validator = new JoomlaApiTokenOwnershipValidator(
            new ApiTokenOwnershipDatabase([$seed, '1']),
            static fn (): string => 'site-secret',
        );

        $this->assertTrue($validator->belongsToUser($token, 42));
    }

    public function testFailsClosedForWrongUserMalformedBase64UnsupportedAlgorithmDisabledSeedAndInvalidHmac(): void
    {
        $seed = base64_encode('seed-material-for-user-42');
        $validHmac = hash_hmac('sha256', base64_decode($seed, true), 'site-secret');

        $cases = [
            ['not base64!', 42, [$seed, '1']],
            [base64_encode('sha256:99:' . $validHmac), 42, [$seed, '1']],
            [base64_encode('sha512:42:' . $validHmac), 42, [$seed, '1']],
            [base64_encode('sha256:42:' . $validHmac), 42, [$seed, '0']],
            [base64_encode('sha256:42:not-the-hmac'), 42, [$seed, '1']],
            [base64_encode('sha256:42:' . $validHmac), 42, ['not-base64', '1']],
        ];

        foreach ($cases as [$token, $userId, $profiles]) {
            $validator = new JoomlaApiTokenOwnershipValidator(
                new ApiTokenOwnershipDatabase($profiles),
                static fn (): string => 'site-secret',
            );

            $this->assertFalse($validator->belongsToUser($token, $userId));
        }
    }
}
