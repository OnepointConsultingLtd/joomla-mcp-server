<?php

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/** Applies Joomla ACL to executors that bypass the Web Services API. */
final class GovernedToolAuthorizer
{
    /** @var callable(int): ?object */
    private $userLoader;

    /** @var null|callable(string, int): ?array{id:int, created_by?:int, type?:string} */
    private $itemResolver;

    public function __construct(
        private readonly ToolAccessPolicy $catalog,
        callable $userLoader,
        ?callable $itemResolver = null
    ) {
        $this->userLoader = $userLoader;
        $this->itemResolver = $itemResolver;
    }

    /** @param array<string, mixed> $arguments */
    public function authorise(AuthenticatedPrincipal $principal, string $toolName, array $arguments): bool
    {
        $policy = $this->catalog->forTool($toolName);
        if ($policy === null) {
            return false;
        }
        if ($policy['kind'] === ToolAccessPolicy::API) {
            return true;
        }

        $user = ($this->userLoader)($principal->userId);
        if (!$this->isActivePrincipalUser($user, $principal->userId)) {
            return false;
        }

        if (($policy['deny'] ?? false) === true) {
            return false;
        }

        if (($policy['special'] ?? null) === 'rendered_page') {
            return $this->authoriseRenderedPage($user, $principal, $arguments);
        }

        $action = $policy['action'];
        if ($action === null) {
            return false;
        }

        $targets = $this->targetsFor($policy, $arguments);
        if ($targets === false) {
            return false;
        }

        foreach ($targets as [$asset, $ownerId]) {
            if ($user->authorise($action, $asset)) {
                continue;
            }

            if (
                $action !== 'core.edit'
                || $ownerId !== $principal->userId
                || !$user->authorise('core.edit.own', $asset)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isActivePrincipalUser(?object $user, int $userId): bool
    {
        return $user !== null
            && isset($user->id)
            && (int) $user->id === $userId
            && (!isset($user->block) || (int) $user->block === 0)
            && method_exists($user, 'authorise');
    }

    /**
     * @param array{kind:string, component:?string, action:?string, asset?:string, id?:string, resolve?:string, associatedIds?:string} $policy
     * @param array<string, mixed> $arguments
     * @return list<array{0:?string, 1:?int}>|false
     */
    private function targetsFor(array $policy, array $arguments): array|false
    {
        if (!isset($policy['asset'])) {
            if (!isset($policy['resolve'])) {
                return [[$policy['component'], null]];
            }

            return $this->resolveItem($policy['resolve'], (int) ($arguments[$policy['id'] ?? 'id'] ?? 0)) === null
                ? false
                : [[$policy['component'], null]];
        }

        $ids = [(int) ($arguments[$policy['id'] ?? 'id'] ?? 0)];
        if (isset($policy['associatedIds'])) {
            $associated = $arguments[$policy['associatedIds']] ?? null;
            if (!is_array($associated)) {
                return false;
            }
            foreach ($associated as $id) {
                $ids[] = (int) $id;
            }
        }

        $targets = [];
        foreach (array_values(array_unique($ids)) as $id) {
            if ($id <= 0) {
                return false;
            }

            $item = isset($policy['resolve'])
                ? $this->resolveItem($policy['resolve'], $id)
                : ['id' => $id];
            if ($item === null) {
                return false;
            }

            $targets[] = [
                $policy['component'] . '.' . $policy['asset'] . '.' . (int) $item['id'],
                isset($item['created_by']) ? (int) $item['created_by'] : null,
            ];
        }

        return $targets;
    }

    /** @return array{id:int, created_by?:int, type?:string}|null */
    private function resolveItem(string $kind, int $id): ?array
    {
        if ($id <= 0 || $this->itemResolver === null) {
            return null;
        }

        $item = ($this->itemResolver)($kind, $id);
        if ($item === null || (int) ($item['id'] ?? 0) !== $id) {
            return null;
        }
        if ($kind === 'plugin_extension' && ($item['type'] ?? null) !== 'plugin') {
            return null;
        }

        return $item;
    }

    /** @param array<string, mixed> $arguments */
    private function authoriseRenderedPage(object $user, AuthenticatedPrincipal $principal, array $arguments): bool
    {
        $articleId = $arguments['article_id'] ?? null;
        $menuItemId = $arguments['menu_item_id'] ?? null;
        $hasArticle = is_int($articleId) && $articleId > 0;
        $hasMenu = is_int($menuItemId) && $menuItemId > 0;

        if ($hasArticle === $hasMenu) {
            return false;
        }

        if ($hasArticle) {
            $article = $this->resolveItem('article', $articleId);
            if ($article === null) {
                return false;
            }

            $asset = 'com_content.article.' . $article['id'];

            return $user->authorise('core.edit', $asset)
                || (
                    isset($article['created_by'])
                    && (int) $article['created_by'] === $principal->userId
                    && $user->authorise('core.edit.own', $asset)
                );
        }

        $item = $this->resolveItem('menu_item', $menuItemId);

        return $item !== null && $user->authorise('core.manage', 'com_menus.item.' . $item['id']);
    }
}
