<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enum\DataSensitivity;
use App\Http\QueryStringList;
use Symfony\Component\HttpFoundation\Request;

final class CatalogCriteria
{
    /**
     * Read-only snapshot of "what the user asked for" on the catalogue page.
     *
     * Holds the search query and each facet selection in one place so the
     * controller can pass a single object to the repository and the
     * template can derive both the active-filter chip rail and the
     * pagination URLs from the same source. Adding a new filter is a
     * single property addition here plus matching lines in
     * {@see self::fromRequest()}, {@see self::activeFilters()}, and
     * {@see self::toQueryArray()}.
     *
     * `q` is the free-text search input, matched against the assistant
     * title and description by the repository. `sort` is the result
     * ordering; it is orthogonal to the filters and so does not count
     * towards {@see self::isEmpty()} or appear in {@see self::activeFilters()}.
     *
     * @param string|null  $q                 optional free-text search query, trimmed and null when empty
     * @param list<string> $languageModels    exact `languageModel` values to keep; empty list means no narrowing on this facet
     * @param list<string> $frameworks        exact `framework` values to keep; empty list means no narrowing on this facet
     * @param list<string> $tags              exact tag names to keep; empty list means no narrowing on this facet
     * @param list<string> $organizations     exact organisation names to keep; empty list means no narrowing on this facet
     * @param list<string> $dataSensitivities exact {@see DataSensitivity} backing values to keep; empty list means no narrowing on this facet
     * @param CatalogSort  $sort              the ordering applied to the result set; defaults to newest-first
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $languageModels = [],
        public readonly array $frameworks = [],
        public readonly array $tags = [],
        public readonly array $organizations = [],
        public readonly array $dataSensitivities = [],
        public readonly CatalogSort $sort = CatalogSort::Newest,
    ) {
    }

    /**
     * Named constructor — parse a `CatalogCriteria` out of an HTTP request.
     *
     * Reads `?q=`, `?language_model[]=`, `?framework[]=`, `?tag[]=`,
     * `?organization[]=`, `?data_sensitivity[]=` and
     * `?sort=` from the query string. Empty values are normalised away so
     * callers can trust the constructed object: a missing query becomes
     * `null`, missing facet selections become `[]`, and a missing or
     * unrecognised sort collapses to {@see CatalogSort::default()}.
     *
     * @param Request         $request the incoming HTTP request whose query string carries the user's selections
     * @param QueryStringList $lists   helper for reading list-shaped query parameters into `list<string>`
     *
     * @return self immutable criteria reflecting the request
     */
    public static function fromRequest(Request $request, QueryStringList $lists): self
    {
        $q = trim((string) $request->query->get('q', ''));

        return new self(
            q: '' === $q ? null : $q,
            languageModels: $lists->fromRequest($request, 'language_model'),
            frameworks: $lists->fromRequest($request, 'framework'),
            tags: $lists->fromRequest($request, 'tag'),
            organizations: $lists->fromRequest($request, 'organization'),
            dataSensitivities: $lists->fromRequest($request, 'data_sensitivity'),
            sort: CatalogSort::fromString($request->query->get('sort')),
        );
    }

    /**
     * Whether the user has applied any filter at all.
     *
     * Useful for deciding whether to render the "Aktive filtre" rail or
     * the "Nulstil filtre" link.
     *
     * @return bool true when no search query is set and every facet is empty
     */
    public function isEmpty(): bool
    {
        return null === $this->q
            && [] === $this->languageModels
            && [] === $this->frameworks
            && [] === $this->tags
            && [] === $this->organizations
            && [] === $this->dataSensitivities;
    }

    /**
     * Yield one {@see ActiveFilter} per applied filter value.
     *
     * The search query (if any) comes first, followed by each
     * Sprogmodel value, then each Rammeværk value, each Tag value, each
     * Kommune value, and finally each Datafølsomhed value — in the order
     * they appear on the criteria. New facets are appended rather than
     * interleaved so existing chip positions stay put. Each entry's
     * `removeQuery` is precomputed so the template can hand it straight
     * to `path()`.
     *
     * @return list<ActiveFilter> ordered as described; empty when {@see self::isEmpty()} is true
     */
    public function activeFilters(): array
    {
        $filters = [];

        if (null !== $this->q) {
            $filters[] = new ActiveFilter(
                type: 'q',
                value: $this->q,
                label: sprintf('"%s"', $this->q),
                removeQuery: $this->without('q', $this->q),
            );
        }

        foreach ($this->languageModels as $value) {
            $filters[] = new ActiveFilter(
                type: 'language_model',
                value: $value,
                label: $value,
                removeQuery: $this->without('language_model', $value),
            );
        }

        foreach ($this->frameworks as $value) {
            $filters[] = new ActiveFilter(
                type: 'framework',
                value: $value,
                label: $value,
                removeQuery: $this->without('framework', $value),
            );
        }

        foreach ($this->tags as $value) {
            $filters[] = new ActiveFilter(
                type: 'tag',
                value: $value,
                label: $value,
                removeQuery: $this->without('tag', $value),
            );
        }

        foreach ($this->organizations as $value) {
            $filters[] = new ActiveFilter(
                type: 'organization',
                value: $value,
                label: $value,
                removeQuery: $this->without('organization', $value),
            );
        }

        foreach ($this->dataSensitivities as $value) {
            // The backing value (`ordinary_personal`) is not readable, so
            // the chip carries the enum's translation key instead — the
            // template runs every chip label through `|trans`, which is a
            // no-op for the facets whose label is already the raw value.
            // An unrecognised value (hand-edited query string) falls back
            // to itself rather than blanking the chip.
            $filters[] = new ActiveFilter(
                type: 'data_sensitivity',
                value: $value,
                label: DataSensitivity::tryFrom($value)?->label() ?? $value,
                removeQuery: $this->without('data_sensitivity', $value),
            );
        }

        return $filters;
    }

    /**
     * Serialise the criteria as a `path()`-compatible query map.
     *
     * Only set values are emitted: a `null` search query is skipped,
     * empty facet lists are skipped, and the sort is emitted only when it
     * differs from {@see CatalogSort::default()} so canonical URLs stay
     * clean. Used as the base map for pagination links so the active
     * filters and the chosen ordering survive page navigation.
     *
     * @return array<string, mixed> map of query-string keys to values, ready for `path('app_assistant_catalog', $map)`
     */
    public function toQueryArray(): array
    {
        $query = [];

        if (null !== $this->q) {
            $query['q'] = $this->q;
        }
        if ([] !== $this->languageModels) {
            $query['language_model'] = $this->languageModels;
        }
        if ([] !== $this->frameworks) {
            $query['framework'] = $this->frameworks;
        }
        if ([] !== $this->tags) {
            $query['tag'] = $this->tags;
        }
        if ([] !== $this->organizations) {
            $query['organization'] = $this->organizations;
        }
        if ([] !== $this->dataSensitivities) {
            $query['data_sensitivity'] = $this->dataSensitivities;
        }
        if (CatalogSort::default() !== $this->sort) {
            $query['sort'] = $this->sort->value;
        }

        return $query;
    }

    /**
     * Produce a query map with a single filter value removed.
     *
     * Backs {@see self::activeFilters()}'s `removeQuery` field. For a
     * scalar filter (`q`) the entire key is dropped. For a list-shaped
     * facet the matching value is filtered out; if the resulting list
     * is empty the key is dropped entirely.
     *
     * @param string $type  filter kind being narrowed (`q`, `language_model`, `framework`, `tag`, `organization`, `data_sensitivity`)
     * @param string $value the specific value to remove from that filter
     *
     * @return array<string, mixed> query map suitable for `path()`
     */
    private function without(string $type, string $value): array
    {
        $query = $this->toQueryArray();

        if ('q' === $type) {
            unset($query['q']);

            return $query;
        }

        $remaining = array_values(array_filter(
            $query[$type],
            static fn (string $v): bool => $v !== $value,
        ));

        if ([] === $remaining) {
            unset($query[$type]);
        } else {
            $query[$type] = $remaining;
        }

        return $query;
    }
}
