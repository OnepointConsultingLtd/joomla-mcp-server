<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\AuditToolFilterService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * A Registry whose reads blow up, standing in for an unreadable component
 * configuration.
 */
final class ExplodingRegistry extends Registry
{
    public function get(string $path, mixed $default = null): mixed
    {
        throw new \RuntimeException('Params unavailable');
    }
}

/**
 * The dashboard's Tool Name audit filter is a dropdown built from what this
 * build registers. These tests run against the real ToolRegistry and
 * PromptRegistry, since the point of the feature is that the list tracks what
 * the system actually exposes rather than a hand-maintained copy.
 *
 * The filtered column holds prompt names as well as tool names (RpcHandler's
 * extractToolName() writes params.name for both tools/call and prompts/get),
 * so both namespaces have to be offered.
 */
final class AuditToolFilterServiceTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $params
     */
    private function service(array $params = []): AuditToolFilterService
    {
        return new AuditToolFilterService(
            new ToolRegistry(),
            new PromptRegistry(),
            new PolicyService(new Registry($params))
        );
    }

    /**
     * @return list<string>
     */
    private function registeredNames(): array
    {
        $names = [];
        foreach ((new ToolRegistry())->getAll() as $tool) {
            $names[] = (string) $tool['name'];
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function promptNames(): array
    {
        $names = [];
        foreach ((new PromptRegistry())->getAll() as $prompt) {
            $names[] = (string) $prompt['name'];
        }

        return $names;
    }

    public function testOptionsCoverEveryRegisteredToolExactlyOnce(): void
    {
        $options = $this->service()->getOptions();

        $offered = array_diff(
            array_merge($options['available'], $options['disabled']),
            $this->promptNames()
        );

        sort($offered);
        $expected = $this->registeredNames();
        sort($expected);

        $this->assertSame($expected, array_values($offered), 'The dropdown must offer every registered tool, once.');
    }

    public function testOptionsAreNotEmpty(): void
    {
        $options = $this->service()->getOptions();

        $this->assertNotEmpty(
            array_merge($options['available'], $options['disabled']),
            'An empty list would silently degrade the filter to a free-text box.'
        );
    }

    public function testEachGroupIsSortedAlphabetically(): void
    {
        $options = $this->service([
            'disabled_tools' => 'search_articles create_article',
        ])->getOptions();

        foreach (['available', 'prompts', 'disabled'] as $group) {
            $sorted = $options[$group];
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $options[$group], "The {$group} group must be sorted for a usable dropdown.");
        }
    }

    /**
     * prompts/get logs the prompt name into the same `tool_name` column as
     * tools/call, so those rows are only filterable if prompts are offered.
     */
    public function testPromptNamesAreOfferedInTheirOwnGroup(): void
    {
        $options = $this->service()->getOptions();

        $this->assertSame($this->promptNames(), $options['prompts']);
        $this->assertContains('draft-article', $options['prompts']);
        $this->assertContains('seo-audit-article', $options['prompts']);
        $this->assertContains('translate-article', $options['prompts']);

        foreach ($this->promptNames() as $prompt) {
            $this->assertNotContains($prompt, $options['available'], 'A prompt must not be presented as a tool.');
        }
    }

    public function testAPromptNameIsNeverTreatedAsUnregistered(): void
    {
        $options = $this->service()->getOptions('draft-article');

        $this->assertSame([], $options['unregistered']);
        $this->assertContains('draft-article', $options['prompts']);
    }

    /**
     * Turning prompts off in Options stops new prompts/get rows but leaves the
     * existing ones, so the names stay selectable — under the label that says
     * why they may now return nothing.
     */
    public function testPromptsSwitchedOffInOptionsMoveToTheDisabledGroup(): void
    {
        $options = $this->service(['prompts_enabled' => 0])->getOptions();

        $this->assertSame([], $options['prompts']);
        foreach ($this->promptNames() as $prompt) {
            $this->assertContains($prompt, $options['disabled']);
        }
    }

    /**
     * Resource URIs are logged into the same column but are unbounded — one
     * per article — so they can only be carried through, never enumerated.
     */
    public function testAResourceUriFilterSurvivesAsUnregistered(): void
    {
        $options = $this->service()->getOptions('joomla://article/12');

        $this->assertSame(['joomla://article/12'], $options['unregistered']);
    }

    public function testToolsDisabledInOptionsAreGroupedSeparatelyButStillOffered(): void
    {
        $options = $this->service(['disabled_tools' => 'search_articles, create_article'])->getOptions();

        // Still selectable: disabling a tool does not delete the rows it has
        // already produced, and auditing those is a normal reason to look.
        $this->assertContains('search_articles', $options['disabled']);
        $this->assertContains('create_article', $options['disabled']);
        $this->assertNotContains('search_articles', $options['available']);
        $this->assertNotContains('create_article', $options['available']);
    }

    public function testEnabledToolsAreOfferedAsAvailable(): void
    {
        $options = $this->service(['disabled_tools' => 'none'])->getOptions();

        $this->assertContains('search_articles', $options['available']);
        $this->assertSame([], $options['disabled']);
    }

    public function testDefaultDisabledToolsAreGroupedAsDisabled(): void
    {
        // No saved value: PolicyService applies its fail-closed default, and
        // the dropdown must reflect the same state the server enforces.
        $options = $this->service()->getOptions();

        $this->assertContains('install_extension', $options['disabled']);
        $this->assertContains('uninstall_extension', $options['disabled']);
        $this->assertContains('update_template_file', $options['disabled']);
    }

    /**
     * A filter the registry does not know — a tool removed by an upgrade, or
     * a hand-edited URL — is still what the query ran, so the dropdown has to
     * show it rather than silently snapping back to "All tools".
     */
    public function testAnAppliedFilterUnknownToTheRegistryIsCarriedThrough(): void
    {
        $options = $this->service()->getOptions('tool_removed_in_an_upgrade');

        $this->assertSame(['tool_removed_in_an_upgrade'], $options['unregistered']);
    }

    public function testAnAppliedFilterThatIsRegisteredIsNotDuplicated(): void
    {
        $options = $this->service()->getOptions('search_articles');

        $this->assertSame([], $options['unregistered']);
        $this->assertContains('search_articles', $options['available']);
    }

    public function testNoAppliedFilterLeavesTheUnregisteredGroupEmpty(): void
    {
        $this->assertSame([], $this->service()->getOptions()['unregistered']);
        $this->assertSame([], $this->service()->getOptions(null)['unregistered']);
        $this->assertSame([], $this->service()->getOptions('')['unregistered']);
    }

    /**
     * Losing the config costs the enabled/disabled split, never the dropdown:
     * a filter you cannot use at all is worse than one that mislabels which
     * tools are currently switched on.
     */
    public function testAnUnreadablePolicyStillYieldsAFullToolList(): void
    {
        $service = new AuditToolFilterService(
            new ToolRegistry(),
            new PromptRegistry(),
            new PolicyService(new ExplodingRegistry())
        );

        $options = $service->getOptions();

        $this->assertSame([], $options['disabled']);
        $this->assertContains('search_articles', $options['available']);
        $this->assertCount(count($this->registeredNames()), $options['available']);
        $this->assertSame($this->promptNames(), $options['prompts']);
    }
}
