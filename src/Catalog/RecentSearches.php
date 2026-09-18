<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\HttpFoundation\RequestStack;

final class RecentSearches
{
    /**
     * Per-session history of the user's most recent catalogue searches.
     *
     * Backs the "Seneste søgninger" sidebar box: the controller records each
     * non-empty `?q=` it serves, and the template renders the stored list as
     * links that re-run the search. State lives in the session so it is
     * per-user and survives navigation without touching the database.
     */
    private const SESSION_KEY = 'catalog.recent_searches';

    /**
     * How many queries to keep. Oldest entries fall off the end once the
     * list grows past this length.
     */
    private const MAX = 5;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Record a search query at the front of the history.
     *
     * Blank queries are ignored. A query already present (compared
     * case-insensitively) is moved to the front rather than duplicated, so
     * the list holds distinct terms in most-recent-first order, capped at
     * {@see self::MAX}.
     *
     * @param string $query the raw search term the user just ran
     */
    public function record(string $query): void
    {
        $query = trim($query);
        if ('' === $query) {
            return;
        }

        $kept = array_filter(
            $this->all(),
            static fn (string $previous): bool => mb_strtolower($previous) !== mb_strtolower($query),
        );
        array_unshift($kept, $query);

        $this->requestStack->getSession()->set(self::SESSION_KEY, \array_slice($kept, 0, self::MAX));
    }

    /**
     * The recorded queries, most recent first.
     *
     * Reads defensively: a session value that is not an array (e.g. a
     * tampered cookie or a schema change) yields an empty list rather than a
     * type error.
     *
     * @return list<string> distinct search terms in most-recent-first order
     */
    public function all(): array
    {
        $stored = $this->requestStack->getSession()->get(self::SESSION_KEY, []);

        return \is_array($stored) ? array_values($stored) : [];
    }
}
