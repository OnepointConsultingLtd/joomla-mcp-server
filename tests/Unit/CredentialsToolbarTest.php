<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Mcpserver\Administrator\View\Credentials\HtmlView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Credentials page's toolbar. It is self-service, so the Options button
 * is offered only to users com_config will let in: anyone else would get a
 * button that leads to "not permitted".
 */
final class CredentialsToolbarTest extends TestCase
{
    /**
     * Builds the page's toolbar for $user.
     *
     * @return list<array{0:string,1:list<mixed>}> The toolbar calls, as
     *         [method, arguments], in the order the page made them.
     */
    private static function toolbarFor(User $user): array
    {
        ToolbarHelper::$calls = [];
        $view = new class extends HtmlView {
            public function buildToolbarFor(User $user): void
            {
                $this->buildToolbar($user);
            }
        };
        $view->buildToolbarFor($user);

        return ToolbarHelper::$calls;
    }

    /**
     * @return list<string> The toolbar methods the page called, in order.
     */
    private static function buttonsFor(User $user): array
    {
        return array_column(self::toolbarFor($user), 0);
    }

    /**
     * @return array<string,array{0:list<string>,1:bool}> Actions granted on
     *         com_mcpserver, and whether the Options button should show.
     */
    public static function grantsAndOptionsVisibility(): array
    {
        return [
            'Configure Options' => [['core.options', 'mcpserver.credential.self'], true],
            'Configure ACL & Options (Super Users inherit it)' => [['core.admin'], true],
            'Manage Own Credentials only' => [['mcpserver.credential.self'], false],
            'Access Administration Interface and audit only' => [['core.manage', 'mcpserver.credential.audit'], false],
        ];
    }

    /**
     * @param list<string> $granted
     */
    #[DataProvider('grantsAndOptionsVisibility')]
    public function testOptionsButtonIsShownOnlyToUsersWhoCanOpenOptions(array $granted, bool $shown): void
    {
        $buttons = self::buttonsFor(FakeUser::granted(...$granted));

        $this->assertSame($shown, in_array('preferences', $buttons, true));
    }

    public function testOptionsButtonOpensThisComponentsOptions(): void
    {
        $calls = self::toolbarFor(FakeUser::granted('core.options'));

        $preferences = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'preferences'));
        $this->assertSame('com_mcpserver', $preferences[0][1][0] ?? null);
    }

    public function testEveryUserGetsTheTitleAndTheWayBack(): void
    {
        $this->assertSame(['title', 'back'], self::buttonsFor(FakeUser::granted('mcpserver.credential.self')));
        $this->assertSame(['title', 'back', 'preferences'], self::buttonsFor(FakeUser::granted('core.admin')));
    }
}
