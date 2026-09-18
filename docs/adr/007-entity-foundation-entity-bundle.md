# 007: Entity foundation via itk-dev/entity-bundle

| Field              | Value                                            |
| ------------------ | ------------------------------------------------ |
| **Created By**     | Troels Ugilt Jensen                              |
| **Date**           | 2026-06-23                                       |
| **Decision Maker** | ITK Dev team                                     |
| **Stakeholders**   | ITK Dev developers, future maintainers of ai-lib |
| **Status**         | Draft                                            |

## Context

ai-lib's domain entities (`User`, `Assistant`, `Organization`) each carried a
bare integer auto-increment id and no cross-cutting bookkeeping. As the
catalogue grows — moderation, sharing, public-sector data subject to GDPR — we
need the same foundations on every entity: creation/update timestamps, "who
touched this" blame tracking, the ability to retire records without destroying
history, an audit trail, and a way to honour erasure requests.

Rather than hand-roll listeners and traits, ITK Dev maintains a reusable
[`itk-dev/entity-bundle`](https://github.com/itk-dev/entity-bundle) that
packages exactly these concerns behind opt-in traits, interfaces, attributes,
and config flags. This ADR records adopting that bundle as the shared entity
foundation for the project. Implements
[#104](https://github.com/itk-dev/ai-lib/issues/104).

The bundle's identity primitive is a **ULID** primary key (its
`AbstractITKDevEntity` mapped superclass assigns a `Symfony\Component\Uid\Ulid`
in its constructor and carries the `#[ITKDevEntity]` discovery marker). Adopting
it therefore also means moving the existing entities off integer ids.

### Drivers

- **Functional:**
  - Uniform `createdAt` / `updatedAt`, `createdBy` / `modifiedBy`, archivable
    state, and an audit log across all entities.
  - GDPR right-to-erasure and retention sweeps for personal data on `User`.
  - A single place new entities inherit these concerns from, so contributors
    don't re-implement them per entity.
- **Non-functional:**
  - Reuse the shared, tested ITK Dev bundle over bespoke per-project code.
  - Stay within Doctrine/Symfony idioms (mapped superclass, attribute mapping,
    `resolve_target_entities`, the UID component's route requirement).
  - Keep the opt-in surface explicit and discoverable.

### Options Considered

1. **Adopt `itk-dev/entity-bundle` via a project base entity (chosen).**
   Introduce `App\Entity\AbstractEntity extends AbstractITKDevEntity`, compose
   the inheritable feature traits on it, and have every entity extend it.
   - Pros: one shared, maintained implementation; uniform behaviour by
     inheritance; ULID ids (non-guessable, collision-free, time-ordered, safe in
     URLs and distributed inserts); features toggle from one config file.
   - Cons: a breaking integer→ULID schema change; couples the project to a
     bundle currently at `0.1.x`; pulls in `damienharper/auditor-bundle`.
2. **Adopt the bundle but annotate each entity directly with `#[ITKDevEntity]`
   and per-entity traits, no shared base.**
   - Pros: per-entity control over which concerns apply.
   - Cons: repetitive; easy to forget a trait on a new entity; no single
     foundation to reason about.
3. **Hand-roll local traits/listeners; keep integer ids.**
   - Pros: no new dependency; no schema break.
   - Cons: re-implements (and must re-test) what the bundle already provides;
     diverges from other ITK Dev projects; audit + anonymization are
     substantial to build well.

## Decision

Adopt **option 1**.

- Require `itk-dev/entity-bundle` (`^0.1.1`, via a Composer VCS repository — it
  is not on Packagist) and register `ITKDev\EntityBundle\ITKDevEntityBundle`
  plus `DH\AuditorBundle\DHAuditorBundle`.
- Introduce `App\Entity\AbstractEntity` — an `#[ORM\MappedSuperclass]` that
  extends the bundle's `AbstractITKDevEntity` (ULID id + `#[ITKDevEntity]`) and
  composes the inheritable concerns: `Timestampable`, `Blameable`,
  `Archivable`, and `AnonymizationStatus` (interface + trait for each).
- `User`, `Assistant`, and `Organization` extend `AbstractEntity`. Their
  integer ids are replaced by the inherited ULID; `getId()` now returns a
  `Ulid`.
- Enable **all bundle features except soft delete** in
  `config/packages/itk_dev_entity.yaml`:

  | Feature       | Enabled | Why                                              |
  | ------------- | ------- | ------------------------------------------------ |
  | timestampable | yes     | created/updated bookkeeping on every entity      |
  | blameable     | yes     | provenance — who created/last modified a record  |
  | archivable    | yes     | retire records without destroying them           |
  | audit         | yes     | field-level change history in `*_audit` tables   |
  | anonymization | yes     | GDPR erasure + retention for personal data       |
  | soft_delete   | **no**  | the catalogue archives rather than soft-deletes  |

  Soft delete is deliberately off: archivable already covers "hide without
  destroying", and running both would give two overlapping "hidden" states with
  two SQL filters. We standardise on archiving.

- `itk_dev_entity.user_class` is set to `App\Entity\User`; the bundle wires
  Doctrine `resolve_target_entities` so the blame relations (`createdBy` /
  `modifiedBy`, typed `?UserInterface`) resolve to the concrete user. This
  makes `User` self-referential on those columns.

### Per-entity opt-in convention

The bundle is opt-in twice — a feature acts only when both the entity opts in
*and* the bundle flag is on. The shared concerns are handled by extending
`AbstractEntity`; the two remaining per-entity opt-ins are:

- **Audit:** mark a concrete entity with `#[Auditable]`. All three current
  entities are auditable.
- **PII anonymization:** annotate personal-data properties with
  `#[Anonymize(strategy: …)]`. On `User`, `email` uses `Strategy::Hash` and
  `name` uses `Strategy::Redact`; `password` is marked `#[AuditIgnore]` so the
  hash never reaches the audit log.

New entities follow the same recipe: `extends AbstractEntity`, add
`#[Auditable]`, and annotate any PII.

### ULID migration

A single migration converts the three primary keys from `INT AUTO_INCREMENT` to
`BINARY(16)` (the Doctrine `ulid` type), adds the trait-backed columns
(`created_at`, `updated_at`, `archived_at`, `anonymized_at`, `created_by_id`,
`modified_by_id`), and creates the `assistant_audit`, `organization_audit`, and
`user_audit` tables. The project has no tagged release and no production data,
so a clean conversion is acceptable; the column changes are ordered before the
blame foreign keys so `user.id` is already `BINARY(16)` when referenced.

The `app_assistant_show` route requirement changes from `\d+` to
`Requirement::ULID`, and the entity value resolver loads assistants by ULID.

## Consequences

### Positive

- Every entity gains timestamps, blame, archivability, audit history, and
  anonymization support from a single shared base — and so will every future
  entity, by default.
- ULID ids are non-sequential and non-guessable (no enumeration of `/assistant/1`,
  `/2`, …), URL-safe, and orderable by creation time.
- Cross-cutting behaviour lives in a maintained, tested ITK Dev bundle rather
  than bespoke project code, keeping ai-lib aligned with sibling projects.
- Audit and erasure — concrete public-sector/GDPR obligations — are wired in
  from the start instead of retrofitted later.

### Negative / Trade-offs

- A breaking integer→ULID schema change. Acceptable now (pre-release, no
  production data); it would be far more painful later.
- A new dependency on `itk-dev/entity-bundle` at `0.1.x` (pre-1.0, so its API
  may still shift) and the transitive `damienharper/auditor-bundle`.
- Blame relations make `User` self-referential and add foreign keys; the
  bundle resolves `UserInterface` to the concrete class via
  `resolve_target_entities`, which must stay configured (`user_class`).
- Audit logging doubles writes for audited entities (the row plus its
  `*_audit` entry) and grows the schema by three tables.
