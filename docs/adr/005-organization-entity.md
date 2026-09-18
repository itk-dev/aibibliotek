# 005: Organization as a first-class entity

| Field              | Value                                                |
| ------------------ | ---------------------------------------------------- |
| **Created By**     | Martin Yde Granath                                   |
| **Date**           | 2026-06-18                                           |
| **Decision Maker** | ITK Dev team                                         |
| **Stakeholders**   | ITK Dev developers, future contributors,             |
|                    | downstream public-sector consumers of `ai-reolen`    |
| **Status**         | Draft                                                |

## Context

The assistant entity needs to carry organisation-level metadata — most
importantly the **framework** the assistant runs on (today: OpenWebUI),
which is decided once per organisation rather than per assistant. If
every user has to re-pick that value on every assistant they create,
the catalogue UX degrades and the data drifts.

Two follow-on concerns also rely on the same model:

- ADR 004 (#60, PR #61) currently uses an env-var allow-list of
  e-mail domains for self-signup. Once organisations are registered
  records, "the set of registered organisations" becomes the natural
  source of truth for who may sign up.
- The catalogue's *Kommuner* facet and the frontpage *Kommuner* stat
  presently fall back to a placeholder because there is nothing in the
  database to count.

This ADR records the decision to introduce **Organization** as a
first-class entity, the (deliberately small) shape of that entity in
this first pass, and what it is intentionally *not* responsible for.

### Drivers

- **Functional:**
  - Derive an assistant's framework from the creating user's
    organisation so the form pre-fills sensibly and stays consistent
    inside one organisation.
  - Give the catalogue a real *Kommuner* dimension to filter and
    count by.
  - Give registration / moderation a real anchor for "is this user
    from a known organisation?" rather than scanning a string list of
    domains.
- **Non-functional:**
  - **Minimal first cut.** The first migration should carry only
    fields the product already needs. Anything speculative is
    deferred to a later ADR or issue so the schema stays cheap to
    evolve.
  - **One source of truth per fact.** Organisation-level defaults
    live on the organisation; per-assistant choices live on the
    assistant. No duplication, no snapshot copies to keep in sync.
  - **Clean entity boundaries.** Each entity owns the relations
    closest to it, so the schema reads like the domain.

### Options Considered

1. **Organization as a first-class Doctrine entity, owning the
   organisation-level fields (chosen).** Adds a real table and gives
   us a place to attach the default framework and the e-mail domain
   list. `User` carries a `ManyToOne` to `Organization`; `Assistant`
   has no direct relation to `Organization`. Pros: simple schema,
   matches how the data is actually owned, easy to grow later. Cons:
   one extra table and a backfill for existing users.
2. **Keep organisation as a string field on `User` / `Assistant`.**
   Pros: no new entity, no migration. Cons: no shared defaults, no
   referential integrity, the domain allow-list and the catalogue
   facet both have to invent their own normalisation, and any future
   org-level field (logo, address, contact e-mail) re-opens the
   question.
3. **Model organisation as a PHP enum / value object, persisted as a
   string.** Pros: cheap, type-safe. Cons: organisations are real
   records that change over time (renames, mergers, new e-mail
   domains), which is exactly what value objects model badly.

### Out of scope (deferred)

The first cut deliberately leaves the following out, to be decided as
each one becomes load-bearing:

- **`Organization ↔ Assistant` relation.** Assistants reach an
  organisation via their author (`Assistant → User → Organization`).
  A direct M:N is not needed today and is not added pre-emptively.
- **Default language model on the organisation.** The original issue
  considered this. We chose *not* to model it: an organisation that
  runs OpenWebUI typically chooses *which* model per assistant, so a
  single default would either be wrong or ignored. The field can be
  added later if the product calls for it.
- **`User → Organization` field.** The reference itself is part of
  this decision; the schema change on `User` (column, migration,
  fixtures, registration / login wiring) is tracked as a separate
  issue.
- **Registration / allow-list switchover.** Whether the env-var
  allow-list from ADR 004 is replaced wholesale by "the set of
  registered organisations", kept as a bootstrap, or hybridised, is
  not decided here. ADR 004 is left unchanged and a future ADR /
  issue will pick that up once the entity lands.

## Decision

Adopt **Organization as a first-class Doctrine entity** with the
following minimal shape:

| Field              | Type                       | Notes                                                         |
| ------------------ | -------------------------- | ------------------------------------------------------------- |
| `id`               | auto-increment `int`       | Primary key.                                                  |
| `name`             | `string`                   | Display name (e.g. "Aarhus Kommune").                         |
| `emails`           | `list<string>` (JSON list) | One or more e-mail domains / addresses owned by the org.      |
| `defaultFramework` | `string`                   | Org-wide default framework for assistants (e.g. `openwebui`). |

Relations:

- `User → Organization`: `ManyToOne`, owned on `User`. A user belongs
  to at most one organisation. The schema change on `User` and the
  derivation of an organisation from the user's e-mail at signup are
  tracked as a separate issue.
- `Assistant → Organization`: **no direct relation.** An assistant's
  organisation is derived from `Assistant.user.organization`.

What this means for behaviour:

- The assistant-creation flow auto-fills `framework` from the user's
  `organization.defaultFramework`. The value is stored on the
  assistant, not read live; this is consistent with how the
  framework can in principle be overridden per assistant.
- The catalogue's *Kommuner* facet / stat is sourced from the
  organisation records reachable via assistants' authors. The
  placeholder in `FrontpageController` is removed once the migration
  and backfill land.
- No language-model field is added to `Organization`. The catalogue
  continues to derive `Sprogmodel` facets from each `Assistant`.

## Consequences

### Positive

- Organisations are a real record, so renames, e-mail-domain changes,
  and future org-level fields (logo, address, contact e-mail) have an
  obvious home that doesn't require touching `User` or `Assistant`.
- The catalogue's *Kommuner* dimension becomes data-backed, replacing
  the placeholder in `FrontpageController`.
- Defaults flow naturally: the assistant-creation form reads the
  user's organisation once and pre-fills.
- The registration / moderation story gains a real anchor for future
  decisions about the allow-list (without committing to a specific
  switchover in this ADR).

### Negative / Trade-offs

- One additional table + migration, plus a backfill for the existing
  baseline `User` fixtures (alice, bob).
- The `emails` list is a JSON column rather than a normalised
  `OrganizationEmail` table, which is cheaper now but means
  uniqueness / lookup-by-domain needs application-level care. If
  domain lookup grows beyond "small allow-list scan" we revisit.
- `Assistant`'s organisation is one extra hop away (`assistant.user
  .organization`), which Doctrine handles cleanly but is worth
  remembering when writing repository queries.
- A small amount of duplication exists between this ADR's "out of
  scope" list and the issues that track each follow-up — accepted
  as the price of keeping the decision record narrow.
