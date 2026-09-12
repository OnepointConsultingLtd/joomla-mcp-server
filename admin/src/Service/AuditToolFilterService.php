<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Builds the choices for the dashboard's Tool Name audit filter from what
 * this build actually registers, so the operator picks a name instead of
 * typing one from memory.
 *
 * The column the filter targets is called `tool_name`, but RpcHandlerTrait's
 * extractToolName() writes three different namespaces into it: a tool name
 * for `tools/call`, a *prompt* name for `prompts/get`, and a resource URI for
 * `resources/read`. The first two are enumerable and are both offered here.
 * Resource URIs are unbounded — one per article — so they cannot be listed; a
 * filter naming one survives through `unregistered` instead.
 *
 * The names are split four ways because an empty result means something
 * different in each case:
 *
 * - `available`    — a tool, callable right now.
 * - `prompts`      — a prompt, retrievable right now via `prompts/get`.
 * - `disabled`     — registered but switched off in Options: a tool named in
 *                    `disabled_tools`, or every prompt when prompts are off.
 *                    Still offered, because switching something off does not
 *                    delete the rows it already produced, and "what did this
 *                    do before we turned it off?" is a normal thing to audit.
 * - `unregistered` — the filter currently in force, when this build knows no
 *                    such name (a resource URI, a tool removed by an upgrade,
 *                    or a hand-edited URL). Carried through so the rendered
 *                    dropdown can never disagree with the filter the query
 *                    actually ran.
 */
final class AuditToolFilterService
{
    public function __construct(
        private ToolRegistry $tools,
        private PromptRegistry $prompts,
        private PolicyService $policy,
    ) {
    }

    /**
     * @param  string|null  $appliedToolName  The tool filter in force, if any.
     *
     * @return array{available: list<string>, prompts: list<string>, disabled: list<string>, unregistered: list<string>}
     */
    public function getOptions(?string $appliedToolName = null): array
    {
        $toolNames   = $this->namesOf($this->tools->getAll());
        $promptNames = $this->namesOf($this->prompts->getAll());

        // Losing the config costs the enabled/disabled split, never the
        // dropdown itself: a filter you cannot use at all is worse than one
        // that mislabels which entries are currently switched on.
        try {
            $disabledTools  = $this->policy->getDisabledTools();
            $promptsEnabled = $this->policy->promptsEnabled();
        } catch (\Throwable $e) {
            $disabledTools  = [];
            $promptsEnabled = true;
        }

        $options = [
            'available'    => [],
            'prompts'      => [],
            'disabled'     => [],
            'unregistered' => [],
        ];

        foreach ($toolNames as $name) {
            $options[in_array($name, $disabledTools, true) ? 'disabled' : 'available'][] = $name;
        }

        foreach ($promptNames as $name) {
            $options[$promptsEnabled ? 'prompts' : 'disabled'][] = $name;
        }

        // Tools and prompts can both land in `disabled`, so re-sort the merge.
        sort($options['disabled'], SORT_STRING);

        $registered = array_merge($toolNames, $promptNames);

        if ($appliedToolName !== null && $appliedToolName !== '' && !in_array($appliedToolName, $registered, true)) {
            $options['unregistered'][] = $appliedToolName;
        }

        return $options;
    }

    /**
     * Sorted, de-duplicated `name` values from a registry's getAll() output.
     *
     * @param  list<array<string, mixed>>  $entries
     *
     * @return list<string>
     */
    private function namesOf(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
