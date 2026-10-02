<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class SearchFilter
{
    /**
     * The multi-token separator for index searches.
     */
    public const TOKEN_SEPARATOR = '|';

    /**
     * Apply a multi-token "LIKE" search across the given columns.
     *
     * Tokens are split on `|`. The result is AND across tokens, OR across columns:
     * a query like "foo|bar" matches rows where SOME column contains "foo" AND
     * SOME (possibly different) column contains "bar".
     *
     * @param  array<int, string>  $columns
     */
    public static function apply(Builder $query, string|array|null $value, array $columns): Builder
    {
        $value = self::term($value);

        if ($value === null) {
            return $query;
        }

        $tokens = array_values(array_filter(
            array_map('trim', explode(self::TOKEN_SEPARATOR, $value)),
            fn ($t) => $t !== ''
        ));

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($token, $columns) {
                foreach ($columns as $i => $column) {
                    if ($i === 0) {
                        $q->where($column, 'LIKE', '%'.$token.'%');
                    } else {
                        $q->orWhere($column, 'LIKE', '%'.$token.'%');
                    }
                }
            });
        }

        return $query;
    }

    /**
     * Normalise a raw filter value into a single search string.
     *
     * A filter value is expected to be a string. It can still arrive as an array when the
     * query string itself carries one (`filter[title][]=a&filter[title][]=b`), which would
     * otherwise blow up on concatenation. Nested arrays are flattened and the parts are
     * re-joined with the token separator, so an array behaves exactly like the equivalent
     * `a|b` input instead of throwing.
     */
    public static function term(string|array|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $parts = [];

        array_walk_recursive($value, function ($part) use (&$parts) {
            if ($part === null || is_array($part)) {
                return;
            }

            $part = trim((string) $part);

            if ($part !== '') {
                $parts[] = $part;
            }
        });

        return $parts === [] ? null : implode(self::TOKEN_SEPARATOR, $parts);
    }
}
