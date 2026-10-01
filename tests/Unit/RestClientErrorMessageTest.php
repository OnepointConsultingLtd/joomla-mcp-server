<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The "API returned HTML" diagnostic is a deliberate, caller-visible message,
 * so it must carry the web-server guidance without the configured Base URL,
 * which can be an internal host (see Resolve Host To IP).
 */
final class RestClientErrorMessageTest extends TestCase
{
    public function testNonJsonResponseMessageKeepsTheGuidanceButNotTheBaseUrl(): void
    {
        $client = new RestClient('http://10.0.0.5:8080', 'token', $this->createMock(LoggerInterface::class));

        $exception = (new ReflectionMethod(RestClient::class, 'nonJsonResponseException'))
            ->invoke($client, 404, 'api/index.php/v1/content/articles/5', '<html><body>Not Found</body></html>');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertStringNotContainsString('10.0.0.5', $exception->getMessage());
        $this->assertSame(
            'The Joomla Web Services API returned HTML instead of JSON (HTTP 404) for api/index.php/v1/content/articles/5. '
            . 'The request did not reach Joomla\'s API application — check the web server configuration: '
            . 'nginx needs a "location /api" block routing to /api/index.php; Apache needs .htaccess with mod_rewrite.',
            $exception->getMessage()
        );
    }
}
