<?php

declare(strict_types=1);

namespace App\Catalog;

final class ActiveFilter
{
    /**
     * One entry in the "Aktive filtre" chip rail.
     *
     * Each chip describes a single filter value the user has applied
     * (a search query, a Sprogmodel value, a Rammeværk value, etc.)
     * together with the query map that, when handed to
     * `path('app_assistant_catalog', ...)`, produces a URL where that
     * specific value is no longer set.
     *
     * @param string               $type        machine name of the filter kind (e.g. `language_model`, `framework`, `q`); matches the `?key=` used in URLs
     * @param string               $value       the filter value itself (the language-model string, the framework string, the search term)
     * @param string               $label       human-readable label shown on the chip; same as `$value` for facets, quoted for search terms
     * @param array<string, mixed> $removeQuery query map that, when passed to `path()`, yields a URL with this specific filter value dropped while preserving the rest
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly string $label,
        public readonly array $removeQuery,
    ) {
    }
}
