# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- PHPStan raised to level max with no baseline, clearing 251 errors across
  `src/` and `tests/`
  ([#250](https://github.com/itk-dev/ai-reolen/issues/250)).
- Removed every `\assert()`; invariants are enforced by guards in `src/` and by
  PHPUnit assertions in `tests/`
  ([#250](https://github.com/itk-dev/ai-reolen/issues/250)).
- `actions/checkout` bumped from v6 to v7 in every workflow
  ([#245](https://github.com/itk-dev/ai-reolen/issues/245)).
- Updated every dependency that moves within its constraint, including the
  Symfony stack from 8.1.0 to 8.1.7
  ([#239](https://github.com/itk-dev/ai-reolen/issues/239)).
- `app:user:create` and `app:user:change-password` prompt for the password
  instead of taking it as an argument, and prompt for a missing e-mail or name
  ([#236](https://github.com/itk-dev/ai-reolen/issues/236)).
- README documents that self-signup stays closed until an organisation exists
  ([#235](https://github.com/itk-dev/ai-reolen/issues/235)).
  - Applied the configured Rector sets across `src/` and `tests/` — 104 files,
    a net deletion of 78 lines
    ([#247](https://github.com/itk-dev/ai-reolen/issues/247)).
- `App\Twig\TextExtension` and `App\Twig\FrameworkExtension` declare their
  filters with `#[AsTwigFilter]` instead of extending `AbstractExtension`
  ([#247](https://github.com/itk-dev/ai-reolen/issues/247)).
- Taskfile task names use the official `namespace:name` colon form, e.g.
  `coding-standards:php:check` and `test:coverage`
  ([#252](https://github.com/itk-dev/ai-reolen/issues/252)).

### Removed

- Unread `REGISTRATION_ALLOWED_EMAIL_DOMAINS` from `.env` and `.env.test`
  ([#235](https://github.com/itk-dev/ai-reolen/issues/235)).

### Added

- [Rector](https://getrector.com/) as a dev dependency, with `rector.php` and
  `task rector:check` / `task rector:apply`
  ([#247](https://github.com/itk-dev/ai-reolen/issues/247)).
- PHPStan static analysis over `src/` and `tests/` with
  `phpstan/phpstan-symfony` and a `task static-analysis:check` target
  ([#249](https://github.com/itk-dev/ai-reolen/issues/249)).
- `UserRepository::collectEmails()`, backing the e-mail completion on
  `app:user:change-password`
  ([#236](https://github.com/itk-dev/ai-reolen/issues/236)).

### Fixed

- `UserApproval::approve()`, not email confirmation, now triggers the
  "you're approved" mail
  ([#267](https://github.com/itk-dev/ai-reolen/issues/267)).
- The admin user list no longer offers Approve for a user awaiting email
  confirmation ([#267](https://github.com/itk-dev/ai-reolen/issues/267)).

## [1.0.2] - 2026-09-18

### Added

- `APP_MAIL_REPLY_TO` env var, applied as a default `Reply-To` header on every
  outbound mail.

### Changed

- Renamed `MAILER_FROM` to `APP_MAIL_FROM`. Behaviour is unchanged.
- `APP_MAIL_FROM` and `APP_MAIL_REPLY_TO` default to `changeme@example.org`
  in `.env`.

## [1.0.1] - 2026-09-18

### Fixed

- The release workflow builds the archive in the server container
  (`COMPOSE_FILE` includes `docker-compose.server.yml`), so the musl Tailwind
  binary pinned under `when@prod` can execute.

## [1.0.0] - 2026-09-17

### Added

- Updated `league/commonmark` to 2.10.1, clearing four advisories against
  2.8.2; the `composer.json` floor moves to `^2.10.1`.
- Woodpecker prod setup and prod config for the Tailwind bundle.
- Unified the Danish terminology for fetching an assistant on "download",
  renamed "vidensopskrift" to "vejledning", and reduced the JSON tab to the
  OpenWebUI download
  ([#219](https://github.com/itk-dev/ai-reolen/issues/219)).
- Strengthened the status colour palette so alert, warning, and error boxes
  clear the 3:1 non-text contrast bar; the responsibility notice moves onto the
  warning palette
  ([#221](https://github.com/itk-dev/ai-reolen/issues/221)).
- Fourth data-sensitivity classification, `DataSensitivity::NoPersonal`, plus a
  `DataSensitivity::example()` accessor feeding a third line on every radio card
  ([#223](https://github.com/itk-dev/ai-reolen/issues/223)).
- Rejected assistant configurations name the accepted formats instead of
  aggregating every adapter's schema errors, and the paste field carries a
  minimal OpenWebUI export as its placeholder
  ([#223](https://github.com/itk-dev/ai-reolen/issues/223)).
- Kommune and Datafølsomhed facets on the catalogue
  ([#225](https://github.com/itk-dev/ai-reolen/issues/225)).
- Removed the framework and data-sensitivity chips from the assistant detail
  header; both values remain in the meta aside.
- Admin screen at `/admin/assistants` listing an organisation's assistants with
  bulk reassignment of ownership, plus an `OrganizationAssistantVoter` letting
  domain managers edit and delete their organisation's assistants
  ([#227](https://github.com/itk-dev/ai-reolen/issues/227)).
- Removed the placeholder "Modelkort" tab from the assistant detail page
  ([#217](https://github.com/itk-dev/ai-reolen/issues/217)).
- Step 2 of the assistant wizard reorders its fields, requires `tagline`, and
  renders data sensitivity as radio cards instead of a dropdown
  ([#202](https://github.com/itk-dev/ai-reolen/issues/202)).
- Data-sensitivity pill on the assistant detail page is colour-coded per
  classification, reusing the semantic alert palette.
- Assistant cards on the catalog grid and frontpage rail render `tagline`,
  falling back to `description`
  ([#200](https://github.com/itk-dev/ai-reolen/issues/200)).
- Viden tab on the assistant detail page replaces the Readme placeholder tab;
  `?tab=readme` no longer resolves
  ([#198](https://github.com/itk-dev/ai-reolen/issues/198)).
- Assistant detail breadcrumb renders the organisation name instead of the raw
  translation identifier
  ([#197](https://github.com/itk-dev/ai-reolen/issues/197)).
- Per-type colour treatment for the `Alert` component, backed by four semantic
  token triples; the `type` prop is renamed from `error` to `danger`
  ([#196](https://github.com/itk-dev/ai-reolen/issues/196)).
- Domain-scoped registration notification via `DomainRegistrationNotifier` and
  `UserRepository::findApproversForDomain()`
  ([#194](https://github.com/itk-dev/ai-reolen/issues/194)).
- Eight integration tests look up their users through the `UserFixtures::…_EMAIL`
  constants instead of creating them inline
  ([#167](https://github.com/itk-dev/ai-reolen/issues/167)).
- Template consolidation pass adding `Icon:Pencil` / `Icon:Upload` /
  `Icon:Trash`, `Form:Textarea`, `Form:Fieldset`, `Form:CsrfInput`, and
  `Filter:Pill`, and adopting them across `templates/`
  ([#164](https://github.com/itk-dev/ai-reolen/issues/164)).
- Password-reset flow at `/reset-password` on
  `symfonycasts/reset-password-bundle`, with admin-editable subject and body
  under **Indstillinger → E-mail**.
- Assistant detail page renders the real organisation, tagline, and
  data-sensitivity values instead of placeholder copy
  ([#188](https://github.com/itk-dev/ai-reolen/issues/188)).
- Edit-an-assistant wizard at `/assistant/{id}/edit`, gated by a new
  `EditAssistantVoter` and backed by `AssistantEditor::update()`
  ([#186](https://github.com/itk-dev/ai-reolen/issues/186)).
- Four curator-facing metadata fields on the wizard's step 2: `organization`,
  `tagline`, `knowledgeDescription`, and `dataSensitivity`
  ([#184](https://github.com/itk-dev/ai-reolen/issues/184)).
- The share wizard is format-agnostic: the file picker accepts `.json` and
  `.modelfile`, and each `FormatAdapter` declares `isExperimental()`
  ([#23](https://github.com/itk-dev/ai-reolen/issues/23)).
- Import and export for four more formats — AI-reolen native, Ollama Modelfile,
  LibreChat preset, and OpenAI Assistants — each a `FormatAdapter`
  ([#23](https://github.com/itk-dev/ai-reolen/issues/23)).
- Base models are translated across systems via `config/model_map.yaml` and a
  `ModelMap` service, with a Choices.js combobox on the wizard's language-model
  field ([#177](https://github.com/itk-dev/ai-reolen/issues/177)).
- Exports are validated against the target format's rules before download
  ([#23](https://github.com/itk-dev/ai-reolen/issues/23)).
- Signup fires only the e-mail confirmation mail; the moderation and welcome
  mails dispatch from `EmailConfirmation::consume()`
  ([#175](https://github.com/itk-dev/ai-reolen/issues/175)).
- The transactional-mail sender is deploy-time-only via `MAILER_FROM`; the
  editable **Afsenderadresse** field is removed.
- **Forhåndsvis** modal and a Markdown cheat-sheet link on every body field at
  `/admin/settings/email`, backed by `POST /admin/settings/email/preview`
  ([#149](https://github.com/itk-dev/ai-reolen/issues/149)).
- OS2ai brand: green/charcoal/sand colour tokens, self-hosted Inter and DM Serif
  Display, and a wordmark in the header
  ([#48](https://github.com/itk-dev/ai-reolen/issues/48),
  [#49](https://github.com/itk-dev/ai-reolen/issues/49)).
- **Mine assistenter** page at `/mine/assistenter`, backed by
  `AssistantRepository::findCreatedBy()`. The placeholder **Favoritter** and
  **Samlinger** nav entries are dropped
  ([#160](https://github.com/itk-dev/ai-reolen/issues/160)).
- Redesigned assistant detail page with a five-tab main column plus meta and
  actions asides, driven by a whitelisted `?tab=` query string, and a
  `paragraphs` Twig filter
  ([#20](https://github.com/itk-dev/ai-reolen/issues/20),
  [#21](https://github.com/itk-dev/ai-reolen/issues/21)).
- `/assistant/new` is a three-step wizard (Indsæt JSON → Gennemgang →
  Kvittering) built on `AbstractFlowType`, with metadata suggestions from
  `AssistantDraftPrefiller`
  ([#20](https://github.com/itk-dev/ai-reolen/issues/20)).
- Single-use e-mail confirmation at `GET /auth/confirm-email/{token}` (24 h TTL)
  transitions a new user from `AwaitingEmailConfirmation` to `Pending`
  ([#119](https://github.com/itk-dev/ai-reolen/issues/119)).
- Supported assistant frameworks are sourced from the registered format
  adapters, with a `SupportedFramework` constraint on
  `Organization::$defaultFramework`
  ([#154](https://github.com/itk-dev/ai-reolen/issues/154)).
- Public-signup allow-list sources its domains from `Organization.emailDomains`
  instead of an env var
  ([#161](https://github.com/itk-dev/ai-reolen/issues/161)).
- The role-picker on `/admin/users` mints its CSRF token at submit time through
  the `csrf-protection` Stimulus helper, fixing the 403 on every role change.
- Dark footer with the OS2ai logo
  ([#47](https://github.com/itk-dev/ai-reolen/issues/47)).
- Admin list pages fill the wide admin container instead of `max-w-4xl`.
- Inline role promotion on `/admin/users` via `POST /admin/users/{id}/role`,
  authorised by `ManageUserVoter` and guarded by a `UserRoles` service that
  refuses to demote the last admin
  ([#148](https://github.com/itk-dev/ai-reolen/issues/148)).
- Three page layout components — `Layout:SingleColumn`, `Layout:ThreeColumn`,
  and `Layout:ContentWithAsides` — on a shared `max-w-wide` container
  ([#144](https://github.com/itk-dev/ai-reolen/issues/144)).
- Every hand-rolled form intent moves to stateless double-submit-cookie CSRF via
  `framework.csrf_protection.stateless_token_ids`
  ([#111](https://github.com/itk-dev/ai-reolen/issues/111)).
- `hero_text` site setting, editable at `/admin/settings/site`, and a
  `SettingFixtures` class seeding every key `SettingsManager` exposes
  ([#127](https://github.com/itk-dev/ai-reolen/issues/127)).
- `/admin/settings/email` validates `admin_recipient` and `sender_address`
  together before persisting either
  ([#132](https://github.com/itk-dev/ai-reolen/issues/132)).
- Transactional e-mail sender is admin-editable at `/admin/settings/email`,
  falling back to the `MAILER_FROM` env var
  ([#132](https://github.com/itk-dev/ai-reolen/issues/132)).
- `assets-build` Taskfile target compiling the Tailwind bundle (supports
  `-- --watch` and `-- --minify`); `site-install` reuses it.
- Catalogue polish: a larger search bar (new `size` prop on `Form:TextInput`,
  `lg` size on `Form:Button`), darker sidebar box headings, and tighter column
  spacing ([#19](https://github.com/itk-dev/ai-reolen/issues/19)).
- Catalogue right-hand sidebar ("Aktive filtre", "Seneste søgninger", "Klar til
  hjemtagning") and one-per-row result cards
  ([#19](https://github.com/itk-dev/ai-reolen/issues/19)).
- Branded 401 and 403 pages via `UnauthorizedEntryPoint` and
  `AccessDeniedHandler`.
- Catalogue result ordering — newest, oldest, recently updated, name A–Å / Å–A —
  carried in `?sort=` and combining with the active filters
  ([#19](https://github.com/itk-dev/ai-reolen/issues/19)).
- Transactional registration e-mails (admin notification and user confirmation)
  rendered from admin-editable Markdown templates by `EmailTemplateRenderer`
  ([#119](https://github.com/itk-dev/ai-reolen/issues/119)).
- Self-service profile edit at `/profile/edit`, where the subject is always read
  off the security context
  ([#128](https://github.com/itk-dev/ai-reolen/issues/128)).
- Catalogue free-text search and a Tags facet; tags become a relational `Tag`
  entity joined many-to-many, recorded in
  [ADR 008](docs/adr/008-tags-as-relational-entity.md)
  ([#18](https://github.com/itk-dev/ai-reolen/issues/18)).
- Anti-flood protection: per-IP and system-wide registration rate limiters
  (HTTP 429) and `login_throttling` on the `main` firewall. Registration is
  idempotent on a duplicate e-mail
  ([#107](https://github.com/itk-dev/ai-reolen/issues/107)).
- `UserFixtures` seeds five extra accounts so every `Roles` value and
  `UserStatus` case is represented, each address exposed as a public constant
  ([#126](https://github.com/itk-dev/ai-reolen/issues/126)).
- Admin roundup: a create form at `/admin/users/new`, the user and organization
  lists on the shared `Table` component, `/admin/settings` split into site and
  e-mail tabs, and brand identity resolved through `SettingsManager`
  ([#124](https://github.com/itk-dev/ai-reolen/issues/124)).
- The `/assistant/new` upload widget pretty-prints the JSON it drops into the
  editable textarea
  ([#101](https://github.com/itk-dev/ai-reolen/issues/101)).
- `Assistant.source_config` JSON column and a create form at `/assistant/new`
  that AJAX-validates the upload through `OpenWebUiConfigValidator`; the
  uploaded file is never persisted
  ([#14](https://github.com/itk-dev/ai-reolen/issues/14)).
- Higher-contrast zebra striping on the `Table` component via a dedicated
  `--color-row-alt` token
  ([#130](https://github.com/itk-dev/ai-reolen/issues/130)).
- `app:user:update` console command updating a user's display name, roles,
  and / or status, backed by `UserManager::updateUser()`
  ([#122](https://github.com/itk-dev/ai-reolen/issues/122)).
- `task site-install` learned an opt-in `RESET=1` parameter that drops and
  recreates the database before migrating.
- Outbound mail foundation: `symfony/mailer` wired to Mailpit, a generic
  `setting` table behind `SettingsManager`, and an admin-only `/admin/settings`
  page for the notification recipient
  ([#119](https://github.com/itk-dev/ai-reolen/issues/119)).
- Fixtures assign a creating user to each seeded assistant and organization, so
  the blame relation is exercised locally.
- Integrated [`itk-dev/entity-bundle`](https://github.com/itk-dev/entity-bundle):
  `App\Entity\AbstractEntity` gives every domain entity a ULID key, timestamps,
  blame, archivability, and anonymization. Recorded in
  [ADR 007](docs/adr/007-entity-foundation-entity-bundle.md)
  ([#104](https://github.com/itk-dev/ai-reolen/issues/104)).
- Admin CRUD for `Organization` at `/admin/organization` per ADR 003
  ([#76](https://github.com/itk-dev/ai-reolen/issues/76)).
- Default-deny `access_control` on the `main` firewall, with a `PUBLIC_ACCESS`
  allow-list for `/login`, `/logout`, `/register`, and `/register/pending`
  ([#97](https://github.com/itk-dev/ai-reolen/issues/97)).
- Authenticated-user dropdown menu in the top nav, with a role-gated
  **Administration** section and the ARIA Menu Button pattern
  ([#108](https://github.com/itk-dev/ai-reolen/issues/108)).
- Shared `Table` Twig component family rendering a semantic `<table>` in a
  horizontal-scroll wrapper, with an `align` prop on the cell components
  ([#112](https://github.com/itk-dev/ai-reolen/issues/112)).
- Anonymous self-signup at `/register` through `App\Security\Registration`,
  landing the user as `Pending` on `/register/pending`
  ([#62](https://github.com/itk-dev/ai-reolen/issues/62)).
- Admin user management at `/admin/users` per ADR 006 — role-scoped listing via
  `UserRepository::findVisibleTo()`, a `?status=` filter, and per-row
  Approve / Block backed by `UserApproval`
  ([#64](https://github.com/itk-dev/ai-reolen/issues/64),
  [#85](https://github.com/itk-dev/ai-reolen/issues/85)).
- `ROLE_DOMAIN_MANAGER` and `ROLE_ADMIN` identifiers, `role_hierarchy` wiring,
  and a domain-scoped `ManageUserVoter` per ADR 006
  ([#84](https://github.com/itk-dev/ai-reolen/issues/84)).
- `User.name` and `User.status` (`UserStatus` enum:
  `awaiting_email_confirmation | pending | approved | blocked`) per ADR 006
  ([#45](https://github.com/itk-dev/ai-reolen/issues/45),
  [#83](https://github.com/itk-dev/ai-reolen/issues/83),
  [#103](https://github.com/itk-dev/ai-reolen/issues/103)).
- `App\Security\AccountStatusChecker` rejecting any non-`Approved` user before
  the password is verified, with a distinct message per state
  ([#63](https://github.com/itk-dev/ai-reolen/issues/63),
  [#103](https://github.com/itk-dev/ai-reolen/issues/103)).
- Shared `Heading` Twig component rendering `<h1>`–`<h6>` with centralised size
  tokens ([#92](https://github.com/itk-dev/ai-reolen/issues/92)).
- Shared `DescriptionList` Twig component family for label/value pairs
  ([#94](https://github.com/itk-dev/ai-reolen/issues/94)).
- Shared `Alert` Twig component whose `type` prop drives the ARIA role
  ([#93](https://github.com/itk-dev/ai-reolen/issues/93)).
- `Organization` entity (name, e-mail domains, default framework), repository,
  migration, and fixtures seeding Aarhus, Aalborg, and Odense
  ([#75](https://github.com/itk-dev/ai-reolen/issues/75)).
- ADR `005-organization-entity` recording `Organization` as a first-class entity
  ([#65](https://github.com/itk-dev/ai-reolen/issues/65)).
- Test-env `framework.exceptions` override logging `NotFoundHttpException` at
  `info` ([#95](https://github.com/itk-dev/ai-reolen/issues/95)).
- Catalogue listing page with filters
  ([#15](https://github.com/itk-dev/ai-reolen/issues/15)).
- User authentication: `User` entity, `UserRepository`, `UserManager`, the
  form-login firewall, `app:user:create` / `app:user:change-password`, and
  baseline fixtures ([#2](https://github.com/itk-dev/ai-reolen/issues/2)).
- Frontpage at `/` with hero, search box, sample-assistant rail, site chrome,
  the `nav_toggle` Stimulus controller, and the `block-on-label` merge gate
  ([#40](https://github.com/itk-dev/ai-reolen/issues/40)).
- PHPUnit suite split into `unit` and `integration`, with transactional database
  isolation per integration test via `dama/doctrine-test-bundle`.
- PHPUnit test harness with a 100 % coverage gate enforced in CI
  ([#31](https://github.com/itk-dev/ai-reolen/issues/31)).
- Reusable `Form/Label`, `Form/Input`, and `Form/Button` Twig components,
  consumed by `/login`.
- Frontend tooling: Tailwind CSS via `symfonycasts/tailwind-bundle`,
  AssetMapper, and Stimulus, with the base Twig layout, asset entrypoints, and
  Tailwind v4 design tokens. Recorded in
  [ADR 002](docs/adr/002-frontend-tooling.md)
  ([#38](https://github.com/itk-dev/ai-reolen/issues/38)).
- Initial Symfony 8 scaffold on the ITK Dev Docker `symfony-8` template
  (phpfpm 8.4, nginx, MariaDB, Mailpit, Traefik) with the coding-standards dev
  dependencies ([#1](https://github.com/itk-dev/ai-reolen/issues/1)).
- Architecture Decision Records under `docs/adr/` with an index and
  `001-tech-stack-docker-symfony`
  ([#11](https://github.com/itk-dev/ai-reolen/issues/11)).
- `Taskfile.yml` exposing common developer commands via `task --list`
  ([#29](https://github.com/itk-dev/ai-reolen/issues/29)).
- Project license declared as **MPL-2.0**, with the full `LICENSE` text and ADR
  `004-project-license-mpl-2`
  ([#32](https://github.com/itk-dev/ai-reolen/issues/32)).
- `CLAUDE.md` with project-level operating instructions for AI agents
  ([#5](https://github.com/itk-dev/ai-reolen/issues/5)).
- Human-facing `README.md` rewritten around the AI Bibliotek catalog
  ([#28](https://github.com/itk-dev/ai-reolen/issues/28)).
- `CONTRIBUTING.md` documenting branching, commits, coding standards, and the
  pull-request workflow
  ([#9](https://github.com/itk-dev/ai-reolen/issues/9)).
- GitHub issue and pull-request templates, each pairing a human-facing resume
  with an "AI specificities" detail block
  ([#69](https://github.com/itk-dev/ai-reolen/issues/69)).
