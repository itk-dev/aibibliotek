# 005: Organization entity and assistant-creation derivation

| Field              | Value                                  |
| ------------------ |----------------------------------------|
| **Created By**     | Martin Yde Granath                     |
| **Date**           | 2026-06-12                             |
| **Decision Maker** | ITK Dev team                           |
| **Stakeholders**   | ITK Dev developers, future maintainers |
| **Status**         | Draft                                  |

## Context

The assistant entity (tracked in
[#14](https://github.com/itk-dev/ai-reolen/issues/14)) needs three
pieces of metadata that pull the design in the same direction:

- **framework** — the AI framework the assistant runs on (OpenWebUI
  today, plausibly others later).
- **language model** — the underlying LLM (e.g. GPT-4o, Claude,
  Mistral, llama).
- **organisations** — which organisations actually use the assistant
  (a list — an assistant may be shared across kommuner).

In practice, **framework** and **language model** are decided at the
organisation level: a municipality commits to a deployment of OpenWebUI
plus a chosen LLM, and every assistant they create runs on that
stack. Asking a user to re-pick those values for every new assistant
is poor UX and creates a long tail of accidentally-misaligned rows.

Two threads converge here:

1. **Assistant** wants defaults derived from the creator's
   organisation rather than free-text input.
2. **ADR 004** (#60) currently uses an env-var
   `REGISTRATION_ALLOWED_EMAIL_DOMAINS` for the self-signup allow-list
   and explicitly anticipates "graduate to a Domain entity" if the
   list grows or needs metadata. It now does — framework and
   language model are exactly that metadata.

Tracked in
[#65](https://github.com/itk-dev/ai-reolen/issues/65).

### Drivers

- **Functional:**
  - Pre-fill framework + language model when a user creates an
    assistant, derived from the user's organisation.
  - One organisation may register several e-mail domains over time;
    a single user belongs to exactly one organisation.
  - The same assistant may be reused by multiple organisations.
- **Non-functional:**
  - Avoid a global env-var becoming the long-term home for what is
    really tabular, per-organisation data.
  - Keep the model open to adding more org-level metadata later
    (contact e-mail, approver(s), branding, …) without another big
    schema migration.
  - Decisions a user makes at assistant-creation time should be
    durable — changing an organisation's default LM later must not
    silently change every existing assistant under it.

### Options Considered

1. **First-class `Organization` entity with org-level metadata
   (framework, language model, e-mail domains).**
   - Pros: one place to query "what does kommune X default to";
     becomes the natural home for the e-mail allow-list (ADR 004
     hand-off); future per-org settings have an obvious column to
     land in.
   - Cons: new central table; ripples into User (org relation) and
     Assistant (org relations + snapshot fields).
2. **Keep env-var allow-list; carry framework + LM on every
   assistant; don't introduce an Organization entity.**
   - Pros: no schema work.
   - Cons: long-term: every assistant carries duplicated text
     (`framework: openwebui`, `language_model: gpt-4o`), no place to
     update the default centrally, no way for admins to manage
     per-org settings without code/env changes.
3. **Make assistants reference the org for framework + LM at read
   time (no snapshot).**
   - Pros: changing an org's defaults updates every assistant for
     free.
   - Cons: the desired behaviour is the opposite — assistants are
     durable artifacts, and an org switching its LM next year must
     not silently rewrite older catalogue entries.

## Decision

Introduce a first-class **`Organization`** entity (option 1).

### Schema

| Field              | Type           | Notes                                                               |
| ------------------ | -------------- | ------------------------------------------------------------------- |
| `id`               | int            | primary key                                                         |
| `name`             | string         | display name ("Aarhus Kommune")                                     |
| `emailDomains`     | json (list)    | one or many domains; matched at signup. Replaces ADR 004's env-var. |
| `defaultFramework` | string (enum?) | populated onto new assistants created by users of this org          |

#### Dropped fields

The earlier draft of this ADR listed two more columns. Both are
deliberately left out of the initial migration (PR #80):

- **`defaultLanguageModel`** — landing it now would require choosing
  a canonical set of model identifiers and a UX for keeping each
  org's row in sync as those identifiers churn. Until at least one
  org actually disagrees with the rest on a language model, every
  new assistant can pick from the same global list the catalog
  already uses, and the snapshot of the chosen model still lives on
  the assistant. Add this column the first time an org genuinely
  needs a different default; the assistant-snapshot field already
  carries the durability guarantee, so backfill is trivial.
- **`createdAt`** — there is no current consumer for the audit
  timestamp (no admin listing sorts by it, no reporting reads it),
  and Doctrine migrations already record when the row was inserted
  in environments that need it. Add when an actual UI or audit
  requirement materialises rather than carrying an unused column
  through every migration in between.

Neither omission affects the derivation flow below — that flow only
needs `defaultFramework`. If `defaultLanguageModel` lands later, the
same flow extends to it by symmetry.

Relations:

- **`User.organization`** — many-to-one. Set at signup time by
  matching the user's e-mail domain against
  `Organization.emailDomains`. Nullable in schema (legacy / future
  edge cases) but the signup path requires a match.
- **`Assistant.organizations`** — many-to-many. The set of orgs that
  use the assistant. The **creator's** organisation is in this set
  on insert; other orgs join by importing / forking later.
- **`Assistant.framework`** + **`Assistant.languageModel`** — **stored
  on the assistant** (snapshot), not joined through the org at read
  time. `framework` is populated from
  `creator.organization.defaultFramework` at creation;
  `languageModel` is picked by the user from the catalog's existing
  global list (no org-level default exists yet — see "Dropped
  fields"). Both are frozen on the assistant unless the user
  explicitly edits.

### Derivation flow at assistant creation

1. User submits the assistant-creation form.
2. Controller (or service) reads `user.organization`.
3. If the form did not specify it explicitly, `assistant.framework`
   defaults to `user.organization.defaultFramework`.
   `assistant.languageModel` has no org-level default (see "Dropped
   fields") and is taken from the form selection against the catalog's
   global model list.
4. `assistant.organizations` is initialised to
   `{user.organization}`. Additional orgs can be added later via the
   share/import flow (#23, #24).

### Interaction with ADR 004

ADR 004's env-var
`REGISTRATION_ALLOWED_EMAIL_DOMAINS` is **superseded by Organization**
as soon as the entity lands. The order of operations:

1. Land Organization + migration (this ADR's primary implementation
   issue).
2. Land the registration flow update (#62) so allow-listing
   == "submitted e-mail matches some `Organization.emailDomains`".
3. Remove the env var (or keep it briefly as a fallback bootstrap).

Until step 1 lands, ADR 004's env-var stays in place as the
bootstrap mechanism — nothing in ADR 004 needs to be re-decided.

### Why snapshot, not live-derive

An assistant is a durable artifact in the catalogue. If Aarhus
Kommune switches its LM next year, the assistants Aarhus shared last
year should keep their original LM in the catalogue listing — both
because that's what they were tested against and because changing
the value silently across hundreds of rows is a misleading audit
event.

Editing an assistant's framework / LM is an explicit user action;
changing an org default is not.

## Consequences

### Positive

- One canonical place to look up "what does kommune X default to";
  admin tooling for editing those defaults is trivial CRUD.
- The assistant-creation form gets a sensible default for the
  framework field that is otherwise typed by hand every time.
  Language model stays user-chosen for now; a default can be added
  per "Dropped fields" once it's actually needed.
- ADR 004's deferred "Domain entity" question is resolved cleanly —
  it's just `Organization`.
- Per-org future metadata (contact mail, approver email, branding,
  feature toggles) lands on the same row without further schema
  invention.

### Negative / Trade-offs

- New central entity touched by signup, assistant creation, and
  admin tooling. The migration is small but the relations matter.
- Assistant rows duplicate framework + language model strings that
  could in theory be derived. We accept this — it's an explicit
  snapshot, not redundant storage, and is what makes the catalogue
  durable.
- One-org-per-user is the simplest mapping; users who genuinely
  belong to two organisations (consultants, partnerships) would need
  a future M:N. Defer until it actually happens.

## Implementation outline

Suggested split (issues to be filed alongside this ADR):

1. **`Organization` entity** — table + repository + migration.
   `User.organization_id` FK added (nullable initially, backfilled,
   then set non-nullable).
2. **Registration allow-list switchover** — `/register` rejects
   e-mails whose domain doesn't match any `Organization.emailDomains`.
   Removes `REGISTRATION_ALLOWED_EMAIL_DOMAINS` env-var dependency.
   Updates ADR 004.
3. **Assistant entity** (lives in #14) gains `framework`,
   `languageModel`, and the `organizations` M:N. The
   assistant-creation flow derives defaults from the creator's
   organisation.
