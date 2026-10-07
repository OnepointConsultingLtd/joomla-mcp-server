<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\JsonRpc;
use PHPUnit\Framework\TestCase;

class JsonRpcTest extends TestCase
{
    public function testNonStringMethodIsNotAValidRequest(): void
    {
        $this->assertNull(JsonRpc::parseRequestData(['jsonrpc' => '2.0', 'id' => 1, 'method' => ['tools/list']]));
    }

    public function testRequestsAndClientResponsesStillParse(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];

        $this->assertSame($request, JsonRpc::parseRequestData($request));
        $this->assertSame($response, JsonRpc::parseRequestData($response));
    }
}
