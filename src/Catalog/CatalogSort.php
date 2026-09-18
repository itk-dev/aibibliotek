<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The orderings a user can apply to the catalogue listing.
 *
 * Backed by the string that travels in the `?sort=` query parameter, so
 * the enum is the single source of truth shared by {@see CatalogCriteria}
 * (which parses the request), the repository (which turns a case into an
 * `ORDER BY`), and the template (which renders the `<select>` from the
 * cases). Adding an ordering is a single case plus a matching arm in
 * {@see self::labelKey()} and in the repository's `applySort()`.
 *
 * `Newest` is the default ordering used when the request carries no sort
 * or an unrecognised value.
 */
enum CatalogSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case RecentlyUpdated = 'recently_updated';
    case NameAsc = 'name';
    case NameDesc = 'name_desc';

    /**
     * The ordering applied when the user has expressed no preference.
     *
     * Newest-first is the natural catalogue default: the most recently
     * shared assistants surface at the top of the listing.
     *
     * @return self the default sort case
     */
    public static function default(): self
    {
        return self::Newest;
    }

    /**
     * Resolve a raw query-string value to a sort case, falling back safely.
     *
     * Used by {@see CatalogCriteria::fromRequest()} to turn the untrusted
     * `?sort=` parameter into a known case. A `null` (parameter absent) or
     * any value that is not a defined case collapses to {@see self::default()}
     * so callers always receive a usable ordering.
     *
     * @param string|null $value the raw `?sort=` value, or null when absent
     *
     * @return self the matching case, or the default when unrecognised
     */
    public static function fromString(?string $value): self
    {
        return (null !== $value ? self::tryFrom($value) : null) ?? self::default();
    }

    /**
     * Translation key for this ordering's human-readable label.
     *
     * Backs the catalogue sort `<select>` option labels. Each case maps to
     * a key under the `catalog.sort` namespace in the message catalogue.
     *
     * @return string the translation key, e.g. `catalog.sort.newest`
     */
    public function labelKey(): string
    {
        return match ($this) {
            self::Newest => 'catalog.sort.newest',
            self::Oldest => 'catalog.sort.oldest',
            self::RecentlyUpdated => 'catalog.sort.recently_updated',
            self::NameAsc => 'catalog.sort.name_asc',
            self::NameDesc => 'catalog.sort.name_desc',
        };
    }
}
