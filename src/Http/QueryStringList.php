<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

final class QueryStringList
{
    /**
     * Read a list-shaped query parameter as a flat array of non-empty strings.
     *
     * Accepts the standard `?key[]=a&key[]=b` form that browsers send for
     * multi-value form controls (e.g. checkbox groups). Drops any entry
     * that is not a string or is the empty string, so callers can hand
     * the result straight to a repository `IN (...)` clause without
     * worrying about scalar coercion or empty filter buckets.
     *
     * @param Request $request the incoming HTTP request
     * @param string  $key     the query-string key holding the list (without the trailing `[]`)
     *
     * @return list<string> ordered as in the original request; duplicates preserved
     *
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException when the parameter cannot be reduced to an array (e.g. `?key=scalar` instead of `?key[]=…`)
     */
    public function fromRequest(Request $request, string $key): array
    {
        $raw = $request->query->all($key);
        $values = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
