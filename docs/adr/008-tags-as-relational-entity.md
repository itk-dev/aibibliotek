# 008: Assistant tags as a relational Tag entity

| Field              | Value                                            |
| ------------------ | ------------------------------------------------ |
| **Created By**     | Troels Ugilt Jensen                              |
| **Date**           | 2026-06-26                                       |
| **Decision Maker** | ITK Dev team                                     |
| **Stakeholders**   | ITK Dev developers, future maintainers of ai-lib |
| **Status**         | Draft                                            |

## Context

The catalogue's faceted filtering (search by free text, narrow by language
model, framework, and tags) is being completed. The language-model and
framework facets already work: the repository filters with `IN (...)` and
counts buckets with `GROUP BY` over a scalar column, and the criteria/chip/URL
plumbing in `App\Catalog` renders them.

Tags did not fit that pattern. `Assistant::$tags` was a `list<string>` stored
in a single `JSON` column. To make tags a real facet — filter assistants that
carry a selected tag, and show a per-tag count in the rail — that JSON array
has to be queried element-wise. Doctrine DQL has no portable operator for "this
JSON array contains value X", and a per-tag count needs the array unnested into
rows before it can be grouped. Both are awkward to express against a JSON
column and diverge from the GROUP BY / `IN` shape the other facets already use.

This ADR records normalizing tags into their own entity so tag filtering and
faceting use the same relational shape as the existing facets. It is part of
the catalogue search/filtering work
([#18](https://github.com/itk-dev/ai-reolen/issues/18)).

### Drivers

- **Functional:**
  - Filter the catalogue by one or more tags (OR-within the tag facet,
    AND-across facets), combinable with free-text search and the other facets.
  - Show an accurate per-tag count in the filter rail.
  - Tags become shared, deduplicated vocabulary rather than free strings
    duplicated per row.
- **Non-functional:**
  - Reuse the established facet pattern (`IN` filter + `GROUP BY` count) instead
    of introducing a second, JSON-specific query style.
  - Stay within Doctrine/Symfony idioms (associations, the existing
    `AbstractEntity` foundation) and avoid a new dependency.

### Options Considered

1. **Normalize tags into a `Tag` entity with a `ManyToMany` relation (chosen).**
   Replace the JSON column with `Assistant ↔ Tag` via an `assistant_tag` join
   table; `Tag` carries a unique `name`.
   - Pros: tag filtering is an `IN`/membership subquery and tag counts are a
     `JOIN … GROUP BY`, identical in spirit to the other facets; tags are
     deduplicated and reusable; opens the door to later tag metadata
     (descriptions, merges, rename-in-one-place).
   - Cons: a schema change (new `tag`, `tag_audit`, and `assistant_tag`
     tables; drop the JSON column); `getTags()` changes from `list<string>` to
     `Collection<Tag>`, rippling into fixtures, templates, and tests.
2. **Keep the JSON column; add a custom `JSON_CONTAINS` DQL function.**
   Register a custom DQL function (or pull in `scienta/doctrine-json-functions`)
   to filter, and aggregate tag counts in PHP.
   - Pros: no schema change; smallest diff to the entity API.
   - Cons: a second, JSON-specific query style alongside the relational facets;
     counts computed in PHP rather than the database; ties filtering to MariaDB
     JSON behaviour; tags stay duplicated free strings with no dedup.
3. **Keep the JSON column; filter and count entirely in PHP.**
   Load assistants and compute everything in application code.
   - Pros: no schema change, no DQL extension.
   - Cons: breaks the `Paginator`/QueryBuilder pagination the catalogue relies
     on; does not scale past a small catalogue; furthest from the existing
     pattern.

## Decision

Adopt **option 1**.

- Introduce `App\Entity\Tag` extending `AbstractEntity` (so it inherits the
  project's ULID id, timestamps, blame, archivable, and audit foundation per
  ADR 007), with a single `name` column under a unique constraint so a tag is
  stored once and shared across assistants. `Tag::__toString()` returns the
  name.
- Replace `Assistant::$tags` (JSON `list<string>`) with a `ManyToMany`
  association to `Tag` over an `assistant_tag` join table. `getTags()` now
  returns a `Collection<Tag>`; `addTag()` / `removeTag()` manage membership.
- Filter in `AssistantRepository::findPaginated()` with an `a.id IN (subquery
  over the join)` so multiple selected tags OR within the facet without
  producing duplicate paginated rows. Count buckets in a new `tagFacetCounts()`
  via `JOIN a.tags t … GROUP BY t.name`, mirroring the scalar facet helpers.
- A migration creates `tag`, `tag_audit`, and `assistant_tag`, then drops the
  `assistant.tags` column. The project has no tagged release and no production
  data, so no JSON→row data backfill is performed; dev and test databases are
  rebuilt from fixtures.

Author/organization filtering — also mentioned on the originating issue — is
**out of scope** here. It requires an `Assistant → Organization` foreign key
that does not yet exist and is tracked as a separate GitHub issue.

## Consequences

### Positive

- Tag filtering and counting use the same relational shape as the language-model
  and framework facets — one query style across the whole filter rail.
- Tags are deduplicated, shared vocabulary; renaming or annotating a tag later
  is a single-row change rather than a sweep across JSON arrays.
- Per-tag counts come from the database, consistent with the other facets and
  compatible with the existing `Paginator` pagination.
- `Tag` inherits audit/blame/timestamps from `AbstractEntity`, so tag
  provenance is tracked like every other entity.

### Negative / Trade-offs

- A schema change: three table operations plus dropping the JSON column.
  Acceptable now (pre-release, no production data); cheaper now than later.
- The `getTags()` contract changes from `list<string>` to `Collection<Tag>`,
  which ripples into `AssistantFixtures`, the card/detail templates
  (`{{ tag }}` → `{{ tag.name }}`), and the entity/repository tests.
- A `Tag` row can become orphaned (referenced by no assistant) once an
  assistant drops it; pruning unused tags is left for a later moderation pass.
