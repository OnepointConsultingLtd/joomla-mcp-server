<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
declare(strict_types=1);
namespace Joomla\Component\Mcpserver\Administrator\Service;
defined('_JEXEC') or die;
interface CredentialStoreInterface
{
    public function findBySelector(string $selector): ?CredentialRecord;
    public function touchLastUsed(int $credentialId): void;
}
