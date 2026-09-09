<?php

namespace App\Support;

/**
 * Helper methods for building safe database queries.
 */
class QueryHelpers
{
    /**
     * Escape LIKE wildcards in user input to prevent SQL injection.
     *
     * Use this whenever incorporating user input into a LIKE clause.
     *
     * @example
     * ```php
     * $escaped = QueryHelpers::escapeLikeWildcards($request->input('search'));
     * $query->where('name', 'LIKE', '%' . $escaped . '%');
     * ```
     */
    public static function escapeLikeWildcards(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }

    /**
     * Build a safe LIKE search pattern from user input.
     *
     * @param  string  $value  The user input to search for
     * @param  string  $position  Where to place wildcards: 'both', 'start', 'end', or 'exact'
     * @return string The escaped search pattern
     *
     * @example
     * ```php
     * // Search for values containing the input
     * $query->where('name', 'LIKE', QueryHelpers::likePattern($input, 'both'));
     *
     * // Search for values starting with the input
     * $query->where('name', 'LIKE', QueryHelpers::likePattern($input, 'start'));
     * ```
     */
    public static function likePattern(string $value, string $position = 'both'): string
    {
        $escaped = self::escapeLikeWildcards($value);

        return match ($position) {
            'start' => $escaped.'%',
            'end' => '%'.$escaped,
            'exact' => $escaped,
            default => '%'.$escaped.'%',
        };
    }
}
