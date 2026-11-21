<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\AuthorizationHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single parser that reads the Authorization header and takes the bearer
 * token out of it, whether that is the shared bearer token, a credential
 * bearer token or an OAuth access token.
 */
final class AuthorizationHeaderTest extends TestCase
{
    public function testReadsTheHeader(): void
    {
        $this->assertSame('Bearer abc', AuthorizationHeader::read(FakeServerInput::withAuthorization('Bearer abc')));
    }

    public function testFallsBackToTheRedirectVariableApacheUsesAfterARewrite(): void
    {
        $server = new FakeServerInput(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']);

        $this->assertSame('Bearer abc', AuthorizationHeader::read($server));
    }

    public function testPrefersTheDirectHeaderOverTheRedirectVariable(): void
    {
        $server = new FakeServerInput([
            'HTTP_AUTHORIZATION' => 'Bearer direct',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected',
        ]);

        $this->assertSame('direct', AuthorizationHeader::bearerTokenFrom($server));
    }

    public function testMissingHeaderReadsAsEmptyAndHasNoToken(): void
    {
        $server = new FakeServerInput();

        $this->assertSame('', AuthorizationHeader::read($server));
        $this->assertNull(AuthorizationHeader::bearerTokenFrom($server));
    }

    public function testBearerTokenIsTheRestOfTheHeader(): void
    {
        $this->assertSame('mcp_abc.def', AuthorizationHeader::bearerToken('Bearer mcp_abc.def'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function notABearerCredential(): array
    {
        return [
            'empty header' => [''],
            'Basic scheme' => ['Basic dXNlcjpwYXNz'],
            'scheme only' => ['Bearer'],
            'scheme and space, no token' => ['Bearer '],
            'lower-case scheme' => ['bearer abc'],
            'scheme not followed by a space' => ['Bearerabc'],
            'leading whitespace' => [' Bearer abc'],
            'tab instead of space' => ["Bearer\tabc"],
        ];
    }

    #[DataProvider('notABearerCredential')]
    public function testHeadersThatAreNotABearerCredentialHaveNoToken(string $header): void
    {
        $this->assertNull(AuthorizationHeader::bearerToken($header));
    }

    /**
     * Extra whitespace is kept, not trimmed, so a padded token never matches a
     * stored one and the request is refused.
     */
    public function testExtraWhitespaceIsKeptInTheToken(): void
    {
        $this->assertSame(' abc', AuthorizationHeader::bearerToken('Bearer  abc'));
        $this->assertSame('abc ', AuthorizationHeader::bearerToken('Bearer abc '));
    }
}
