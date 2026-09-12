<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

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

    private const SECRET = 'site-secret';

    /** Build a token the way plg_user_token does: base64("algo:userId:hmac"). */
    private function token(string $algo, int $userId, string $rawSeed, string $secret = self::SECRET): string
    {
        return base64_encode($algo . ':' . $userId . ':' . hash_hmac($algo, $rawSeed, $secret));
    }

    private function validator(?string $seed, ?string $enabled): JoomlaApiTokenOwnershipValidator
    {
        // profileValue() is called for the seed first, then the enabled flag.
        return new JoomlaApiTokenOwnershipValidator(
            new ApiTokenOwnershipDatabase([$seed, $enabled]),
            static fn (): string => self::SECRET
        );
    }

    /**
     * Joomla's token plugin accepts sha256 and sha512 and reads the algorithm
     * from its form file, so a site issuing sha512 tokens must not be told the
     * token belongs to someone else.
     */
    public function testSha512TokensAreAccepted(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');

        $this->assertTrue($validator->belongsToUser($this->token('sha512', 42, $raw), 42));
    }

    public function testSha256TokensAreStillAccepted(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');

        $this->assertTrue($validator->belongsToUser($this->token('sha256', 42, $raw), 42));
    }

    public function testAnUnsupportedAlgorithmIsReportedAsSuch(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');
        $token = base64_encode('md5:42:' . hash_hmac('md5', $raw, self::SECRET));

        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_ALGORITHM,
            $validator->check($token, 42)
        );
    }

    public function testATokenForAnotherUserIsReportedAsWrongUser(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');

        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_WRONG_USER,
            $validator->check($this->token('sha256', 99, $raw), 42)
        );
    }

    public function testADisabledTokenIsReportedAsNotEnabled(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '0');

        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_NOT_ENABLED,
            $validator->check($this->token('sha256', 42, $raw), 42)
        );
    }

    public function testAnAccountWithNoTokenIsReportedAsNoSeed(): void
    {
        $validator = $this->validator(null, '1');

        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_NO_SEED,
            $validator->check($this->token('sha256', 42, 'seed-bytes'), 42)
        );
    }

    public function testAWrongSecretIsReportedAsMismatchNotWrongUser(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');
        $token = $this->token('sha256', 42, $raw, 'a-different-site-secret');

        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_MISMATCH,
            $validator->check($token, 42)
        );
    }

    public function testGarbageIsReportedAsMalformed(): void
    {
        $validator = $this->validator(base64_encode('seed'), '1');

        $this->assertSame(JoomlaApiTokenOwnershipValidator::REASON_MALFORMED, $validator->check('not-a-token!!', 42));
        $this->assertSame(JoomlaApiTokenOwnershipValidator::REASON_MALFORMED, $validator->check('', 42));
    }

    public function testSurroundingWhitespaceFromCopyPasteIsTolerated(): void
    {
        $raw = 'seed-bytes';
        $validator = $this->validator(base64_encode($raw), '1');

        $this->assertTrue($validator->belongsToUser("  " . $this->token('sha256', 42, $raw) . "\n", 42));
    }
    public function testTokenStateReportsAMissingTokenBeforeAnyClaimIsAttempted(): void
    {
        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_NO_SEED,
            $this->validator(null, '1')->tokenState(42)
        );
    }

    public function testTokenStateReportsADisabledToken(): void
    {
        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::REASON_NOT_ENABLED,
            $this->validator(base64_encode('seed-bytes'), '0')->tokenState(42)
        );
    }

    public function testTokenStateIsOkForAUsableToken(): void
    {
        $this->assertSame(
            JoomlaApiTokenOwnershipValidator::OK,
            $this->validator(base64_encode('seed-bytes'), '1')->tokenState(42)
        );
    }
}
