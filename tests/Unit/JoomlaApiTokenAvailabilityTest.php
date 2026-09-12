<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\JoomlaApiTokenAvailability;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class TokenAvailabilityQuery implements QueryInterface
{
    public function select(array|string $columns): self { return $this; }
    public function from(array|string $tables): self { return $this; }
    public function where(array|string $conditions, string $glue = 'AND'): self { return $this; }
    public function update(string $table): self { return $this; }
    public function set(array|string $values): self { return $this; }
    public function insert(string $table): self { return $this; }
    public function columns(array|string $columns): self { return $this; }
    public function values(array|string $values): self { return $this; }
    public function __toString(): string { return ''; }
}

final class TokenAvailabilityDatabase implements DatabaseInterface
{
    /**
     * @param array{enabled:int,params:string}|null $pluginRow
     * @param list<string>                          $groupTitles
     */
    public function __construct(
        private ?array $pluginRow,
        private array $groupTitles = [],
        private bool $throw = false
    ) {
    }

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        return is_array($name) ? $name : $name;
    }

    public function quote(array|string $text, bool $escape = true): array|string { return $text; }
    public function getQuery(bool $new = false): QueryInterface|string { return new TokenAvailabilityQuery(); }
    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self { return $this; }

    public function loadAssoc(): ?array
    {
        if ($this->throw) {
            throw new \RuntimeException('database is down');
        }

        return $this->pluginRow;
    }

    /** @return list<string> */
    public function loadColumn(int $offset = 0): array { return $this->groupTitles; }

    public function loadResult(): mixed { return null; }
    public function execute(): bool { return true; }
}

/**
 * The approval queue flags a request that could never be claimed, because the
 * requester's group has no route to a Joomla API token.
 */
final class JoomlaApiTokenAvailabilityTest extends TestCase
{
    private function service(
        ?array $params,
        array $userGroups,
        int $enabled = 1,
        array $groupTitles = []
    ): JoomlaApiTokenAvailability {
        $row = $params === null
            ? ['extension_id' => 401, 'enabled' => $enabled, 'params' => '{}']
            : ['extension_id' => 401, 'enabled' => $enabled, 'params' => json_encode($params)];

        return new JoomlaApiTokenAvailability(
            new TokenAvailabilityDatabase($row, $groupTitles),
            static fn (int $id): array => $userGroups
        );
    }

    public function testSuperUserIsAllowedByTheStockDefault(): void
    {
        // No allowedUserGroups stored at all: plg_user_token defaults to [8].
        $result = $this->service(null, [8])->forUser(42);

        $this->assertTrue($result['can_create']);
    }

    /**
     * The case that broke the flow: a stock site permits Super Users only, so an
     * ordinary requester has no API Tokens tab and can never claim.
     */
    public function testOrdinaryUserIsBlockedByTheStockDefault(): void
    {
        $result = $this->service(null, [2, 3], 1, ['Registered', 'Author'])->forUser(42);

        $this->assertFalse($result['can_create']);
        $this->assertTrue($result['plugin_enabled']);
        $this->assertSame(['Registered', 'Author'], $result['group_names'], 'the approver is told which groups to add');
    }

    public function testUserIsAllowedOnceTheirGroupIsAdded(): void
    {
        $result = $this->service(['allowedUserGroups' => [8, 6]], [6])->forUser(42);

        $this->assertTrue($result['can_create']);
        $this->assertSame([], $result['group_names']);
    }

    /**
     * plg_user_token treats an empty allowedUserGroups as "every group is
     * permitted", not "none". Inverting this would wrongly flag every request
     * on a site that deliberately opened tokens up.
     */
    public function testEmptyAllowedGroupsMeansEveryoneIsPermitted(): void
    {
        $this->assertTrue($this->service(['allowedUserGroups' => []], [2])->forUser(42)['can_create']);
    }

    public function testDisabledPluginIsReportedSeparatelyFromAGroupProblem(): void
    {
        $result = $this->service(['allowedUserGroups' => [2]], [2], 0, ['Registered'])->forUser(42);

        $this->assertFalse($result['can_create']);
        $this->assertFalse($result['plugin_enabled'], 'enabling the group would not help here');
    }

    public function testInheritedGroupMembershipCounts(): void
    {
        // getGroupsByUser(..., true) returns inherited groups too.
        $result = $this->service(['allowedUserGroups' => [8]], [8, 2])->forUser(42);

        $this->assertTrue($result['can_create']);
    }

    public function testASingleNonArrayAllowedGroupIsHandled(): void
    {
        $this->assertTrue($this->service(['allowedUserGroups' => 6], [6])->forUser(42)['can_create']);
    }

    /**
     * This is a diagnostic shown beside an approve button. If it cannot be
     * computed it must assume nothing is wrong rather than block the queue.
     */
    public function testALookupFailureDoesNotFlagTheRequest(): void
    {
        $service = new JoomlaApiTokenAvailability(
            new TokenAvailabilityDatabase(null, [], true),
            static fn (int $id): array => [2]
        );

        $result = $service->forUser(42);

        $this->assertTrue($result['can_create']);
        $this->assertTrue($result['plugin_enabled']);
    }

    public function testAnUninstalledPluginIsReportedAsDisabled(): void
    {
        // No row in #__extensions at all: the plugin was never installed.
        $service = new JoomlaApiTokenAvailability(
            new TokenAvailabilityDatabase(null),
            static fn (int $id): array => [2]
        );

        $result = $service->forUser(42);

        $this->assertFalse($result['can_create']);
        $this->assertFalse($result['plugin_enabled']);
    }

    public function testTheBlockedWarningCarriesThePluginExtensionIdToLinkTo(): void
    {
        $result = $this->service(null, [2, 3], 1, ['Registered'])->forUser(42);

        $this->assertFalse($result['can_create']);
        $this->assertSame(401, $result['extension_id'], 'needed to link straight to the plugin');
    }

    public function testAnUninstalledPluginHasNoExtensionIdToLinkTo(): void
    {
        $service = new JoomlaApiTokenAvailability(
            new TokenAvailabilityDatabase(null),
            static fn (int $id): array => [2]
        );

        $this->assertNull($service->forUser(42)['extension_id']);
    }
}
