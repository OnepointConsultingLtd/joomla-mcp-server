<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

class PolicyService
{
    /**
     * Tools blocked until an admin explicitly allows them. These grant arbitrary
     * code execution on the server (extension install/uninstall, PHP template
     * edits), so they must be opt-in. Mirrors the fail-closed reasoning in
     * AuthService: config.xml defaults are only persisted once the options are
     * saved, so the same default must be applied here for fresh installs.
     */
    private const DEFAULT_DISABLED_TOOLS = 'install_extension uninstall_extension update_template_file';

    /**
     * Sentinel the admin enters to explicitly disable nothing. Required because
     * Joomla's Registry treats an empty saved value as "unset" and falls back to
     * the field default, so a cleared textarea can never round-trip through the
     * config UI — the defaults reappear on every save.
     */
    private const NONE_SENTINEL = 'none';

    public function __construct(private readonly Registry $params)
    {
    }

    public function isToolAllowed(string $toolName): bool
    {
        return !in_array($toolName, $this->getDisabledTools(), true);
    }

    /**
     * When read-only mode is on, only tools annotated readOnlyHint may run.
     */
    public function isReadOnly(): bool
    {
        return (bool) $this->params->get('read_only', 0);
    }

    /**
     * Resources are a read-only feature gate, not a security control, so
     * fail-open (default 1) matching config.xml for fresh installs.
     */
    public function resourcesEnabled(): bool
    {
        return (bool) $this->params->get('resources_enabled', 1);
    }

    /**
     * Prompts are a read-only feature gate, not a security control, so
     * fail-open (default 1) matching config.xml for fresh installs.
     */
    public function promptsEnabled(): bool
    {
        return (bool) $this->params->get('prompts_enabled', 1);
    }

    /**
     * @return list<string>
     */
    public function getDisabledTools(): array
    {
        return $this->parseToolList((string) $this->params->get('disabled_tools', self::DEFAULT_DISABLED_TOOLS));
    }

    /**
     * @return list<string>
     */
    private function parseToolList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $tools = preg_split('/[\s,]+/', $value) ?: [];

        return array_values(array_filter(
            array_map('trim', $tools),
            static fn (string $tool): bool => $tool !== '' && strtolower($tool) !== self::NONE_SENTINEL
        ));
    }
}
