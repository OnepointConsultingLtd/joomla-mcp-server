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
 * Ranks completion candidates for completion/complete: prefix matches first,
 * then substring matches, case-insensitively and literally (the typed value is
 * never a pattern), capped at the 100 values the protocol allows.
 */
final class CompletionService
{
    public const MAX_VALUES = 100;

    /**
     * @param  list<string>  $candidates
     * @return array{values: list<string>, total: int, hasMore: bool}
     */
    public static function complete(array $candidates, string $typed): array
    {
        $needle = mb_strtolower($typed);
        $prefix = [];
        $contains = [];

        foreach (array_unique($candidates) as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $haystack = mb_strtolower($candidate);
            if ($needle === '' || str_starts_with($haystack, $needle)) {
                $prefix[] = $candidate;
            } elseif (str_contains($haystack, $needle)) {
                $contains[] = $candidate;
            }
        }

        $matches = [...$prefix, ...$contains];

        return [
            'values' => array_slice($matches, 0, self::MAX_VALUES),
            'total' => count($matches),
            'hasMore' => count($matches) > self::MAX_VALUES,
        ];
    }
}
