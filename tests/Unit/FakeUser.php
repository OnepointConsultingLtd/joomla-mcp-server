<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * A Joomla user granted a fixed set of actions on com_mcpserver, and nothing
 * anywhere else.
 */
final class FakeUser extends User
{
    /**
     * @param list<string> $granted Actions the user is granted on com_mcpserver.
     */
    private function __construct(private array $granted)
    {
    }

    public static function granted(string ...$actions): self
    {
        return new self(array_values($actions));
    }

    public function authorise($action, $assetname = null): bool
    {
        return $assetname === 'com_mcpserver' && in_array($action, $this->granted, true);
    }
}
