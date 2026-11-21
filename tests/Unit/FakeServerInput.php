<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Input\Input;

/**
 * Stands in for the request's server input (`$app->input->server`), which
 * AuthorizationHeader reads through getString().
 */
final class FakeServerInput extends Input
{
    /** @var array<string,int> How many times each variable was read. */
    public array $reads = [];

    /**
     * @param array<string,string> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public static function withAuthorization(string $header): self
    {
        return new self(['HTTP_AUTHORIZATION' => $header]);
    }

    public function getString(string $name, string $default = ''): string
    {
        $this->reads[$name] = ($this->reads[$name] ?? 0) + 1;

        return $this->values[$name] ?? $default;
    }
}
