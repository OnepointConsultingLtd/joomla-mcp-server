<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\McpCredential;
use PHPUnit\Framework\TestCase;

class McpCredentialTest extends TestCase
{
    public function testGeneratedCredentialHasStrictBearerFormatAndVerifies(): void
    {
        $credential = McpCredential::issue();

        $parsed = McpCredential::parseBearer('Bearer ' . $credential['token']);

        $this->assertNotNull($parsed);
        $this->assertSame($credential['selector'], $parsed['selector']);
        $this->assertTrue(McpCredential::verify($parsed['secret'], $credential['verifier']));
    }

    public function testIssuedTokensUseTheBase64UrlAlphabet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression(
                '/^mcp_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}$/D',
                McpCredential::issue()['token']
            );
        }
    }

    public function testAcceptsStandardBase64TokensIssuedByTheBroken1_10_1Build(): void
    {
        $selector = 'Ab+/Cd+eF/gh-_ij';
        $secret = str_repeat('a+b/', 10) . 'c-_';

        $parsed = McpCredential::parseBearer('Bearer mcp_' . $selector . '.' . $secret);

        $this->assertSame(['selector' => $selector, 'secret' => $secret], $parsed);
    }

    public function testMalformedAndWrongBearerCredentialsAreRejected(): void
    {
        $credential = McpCredential::issue();

        $this->assertNull(McpCredential::parseBearer('Basic ' . $credential['token']));
        $this->assertNull(McpCredential::parseBearer('Bearer mcp_invalid'));
        $this->assertFalse(McpCredential::verify('wrong-secret', $credential['verifier']));
    }
}
