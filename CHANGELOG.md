# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- GitHub Actions now run PHPStan on pull requests.
- PHPStan moves from level 8 with a 102-entry baseline to **level max with no
  baseline at all**, clearing 251 errors across `src/` and `tests/`. The
  baseline was deferred work rather than a list of accepted findings, and it
  hid real ones: `User::getUserIdentifier()` was annotated `non-empty-string`
  while `DomainRegistrationNotifier` depends on the empty case to skip a user
  without an address. Fixes narrow rather than cast — a blanket `(string)`
  silences the analyser by hiding exactly the case it points at. `src/Kernel.php`
  is excluded as generated framework code, and two `@phpstan-ignore` lines
  remain, each naming its reason inline.
- Every `\assert()` is gone. Both container images set `zend.assertions=-1`,
  so none of them ever executed — in development, under test, or in production.
  `src/` enforces its invariants with guards that throw; `tests/` uses the
  PHPUnit assertion, which both runs and preserves the static narrowing through
  `@phpstan-assert`. The test suite gained roughly 450 assertions as a result,
  all of them previously dead. Three `assertNotNull()` calls on a non-nullable
  `Ulid` were removed instead of converted: they asserted a guarantee the type
  system already makes.
- `actions/checkout` moves from v6 to v7 across every workflow, matching the
  version upstream `devops_itkdev-docker` now mirrors. It is the only GitHub
  Action the project uses. Upstream also reindented the mirrored files from
  four spaces to two; that is deliberately not copied, since it would bury a
  one-line change per file in a reformat and `.github/` is outside the
  project's Prettier glob either way.
- Updated every dependency that moves within its existing constraint. The
  Symfony stack goes from 8.1.0 to 8.1.7 across the board — seven patch
  releases of fixes that had accumulated in `framework-bundle`,
  `security-bundle`, `form`, `validator`, `mailer` and `console` — alongside
  `doctrine/orm` 3.6.7 to 3.7.1, `doctrine/doctrine-bundle` 3.2.4 to 3.3.2,
  and the Twig and PHP CS Fixer tooling. `composer.json` carries
  `"bump-after-update": true`, so the constraints move to the installed
  versions with the lock. No advisories were outstanding, but the same gap is
  how the `league/commonmark` advisories reached a release branch.
- README states that self-signup stays closed until an organisation exists.
  A fresh install rejects every registration, by design, and nothing said so;
  the note explains the bootstrap path via `app:user:create` and
  `/admin/organization`. The `@param` on `App\Security\Registration` no longer
  claims the allow-list is parsed from an env var.
- `app:user:create` and `app:user:change-password` no longer accept the
  password as an argument. It is prompted for with `askHidden()` every time.
  A password on the command line survives in shell history, is readable in
  the process list for as long as the command runs, and is echoed into
  deployment logs — and there was no way to avoid it, since neither command
  implemented `interact()`. The argument is removed outright rather than made
  optional, so nothing can quietly keep passing one.
- `app:user:create` takes the e-mail and display name as optional arguments
  and prompts for whichever is missing; `app:user:change-password` does the
  same for the e-mail, completing against the addresses already in the user
  table, since rotating a password usually starts with finding the account.
  Both fail with a clear message under `--no-interaction`, where the password
  cannot be collected.
- Applied the configured Rector sets across `src/` and `tests/` — 104 files,
  a net deletion of 78 lines. The bulk is mechanical: 28 classes become
  `readonly`, `new Foo()->bar()` loses its parentheses under PHP 8.4, dead
  assignments and redundant casts go, arrow functions gain return types, and
  PHPUnit mocks become stubs where nothing is asserted on them. No Doctrine
  entity was made `readonly`, which would have broken hydration.
- `App\Twig\TextExtension` and `App\Twig\FrameworkExtension` no longer extend
  `AbstractExtension`; their filters are declared with Twig's `#[AsTwigFilter]`
  attribute instead. This is the one behavioural change in the pass —
  registration moves from a `getFilters()` method to container
  autoconfiguration — and the two tests that asserted registration by calling
  `getFilters()` now build a Twig environment from the attributes and render
  through the filter, which checks that it works rather than that it is
  declared.
- Taskfile task names follow the [official style guide](https://taskfile.dev/docs/styleguide#use-a-colon-to-separate-the-task-namespace-and-name)
  and use a colon between namespace and name: `coding-standards:php:check`
  rather than `coding-standards-php-check`, `test:coverage` rather than
  `test-coverage`, and so on. `task --list` now groups by namespace instead of
  presenting thirty flat kebab-case names. Single-word tasks — `compose`,
  `composer`, `console`, `test` — have no namespace and are unchanged.
  The tasks added further down the stack follow the same rule:
  `static-analysis:check`, `rector:check` and `rector:apply`.
  `README.md`, `CONTRIBUTING.md` and `CLAUDE.md` follow suit. Earlier
  changelog entries keep the old names, since they record what was true when
  written.

### Removed

- `REGISTRATION_ALLOWED_EMAIL_DOMAINS` from `.env` and `.env.test`. Nothing
  read it: the signup allow-list is collected from `Organization.emailDomains`
  via `OrganizationRepository::collectAllowedEmailDomains()`, so the variable
  and its comments described behaviour that no longer existed. Leaving it in
  place was worse than useless — a production signup was rejected for a domain
  the comment in `.env` claimed was allowed.

### Added

- `UserRepository::collectEmails()`, the read-side lookup backing that
  completion.
- [Rector](https://getrector.com/) as a dev dependency, with `rector.php`,
  `task rector-check` / `task rector-apply`, and a section in `CLAUDE.md`.
  The coding-standards family decides how code is laid out; Rector decides
  what it says, which is the half that was missing — and the one that pays
  off on the major upgrades currently queued.
  The configuration covers `src/` and `tests/`. `withComposerBased()` enables
  the Symfony, Doctrine, PHPUnit and Twig rules that match the installed
  versions, so migrations follow `composer.lock` rather than a pinned version;
  on top sit the PHP 8.4 migration and the dead-code, code-quality and
  type-declaration sets. Sets that rewrite structure rather than expression —
  naming, privatization, early return — are left off, since their output needs
  judging line by line. `RemoveDeadInstanceOfAssertRector` is skipped
  explicitly: `\assert($x instanceof Foo)` after `createForm()` looks redundant
  to static analysis precisely because the assert is what narrows the type, so
  removing it makes the declared return type stop matching — the rule converts
  a non-issue into a real error that no test catches, since runtime behaviour
  is unchanged. `task rector-check` therefore reports changes on the
  current codebase, since the sets have never been applied; it is not wired
  into CI, and applying them is a separate pull request so the rewrite is
  reviewed on its own terms rather than riding along with a feature.
- PHPStan static analysis at level 8 over `src/` and `tests/`, with
  `phpstan/phpstan-symfony` resolving container services, a
  `task static-analysis-check` target, and a section in `CLAUDE.md`. Nothing
  type-checked the code before this: undefined methods, wrong argument types
  and impossible conditions all passed CI, since the 100% coverage gate proves
  lines execute rather than that types line up.
- `phpstan-baseline.neon` capturing the 102 errors that already existed, so
  the gate protects new code immediately instead of waiting on a cleanup. The
  baseline is deferred work, not an allow-list; clearing it is tracked
  separately.

## [1.0.2] - 2026-09-18

### Added

- `APP_MAIL_REPLY_TO` env var, applied as a default `Reply-To` header on every
  outbound mail through `framework.mailer.headers` in
  `config/packages/mailer.yaml`. Transactional mail is sent from an unattended
  address, so replies had nowhere to go; the header points them at a monitored
  mailbox without touching the five notifiers, none of which set a `Reply-To`
  of their own.

### Changed

- Renamed `MAILER_FROM` to `APP_MAIL_FROM`. `MAILER_*` is the namespace
  Symfony's mailer recipe owns — it manages `MAILER_DSN` between the
  `###> symfony/mailer ###` markers in `.env` — so an application variable
  sitting next to it invited both confusion and a future collision. The
  `APP_` prefix marks the two addresses as ours. Behaviour is unchanged:
  `SettingsManager::getSenderAddress()` still returns `null` for an empty
  value, and notifiers still skip the send rather than fail.
- Both `APP_MAIL_FROM` and `APP_MAIL_REPLY_TO` default to
  `changeme@example.org` in `.env`, so a fresh checkout carries a
  visibly-wrong placeholder instead of an empty string. `APP_MAIL_REPLY_TO`
  must not be left empty in a deploy: it is set as a default header, and an
  unparseable address makes the send throw instead of skipping the way an
  unset `APP_MAIL_FROM` does.

## [1.0.1] - 2026-09-18

### Fixed

- The release workflow now builds the archive in the same container the
  servers run. `.github/workflows/github_build_release.yml` sets
  `COMPOSE_FILE` to `docker-compose.yml:docker-compose.server.yml`, so
  `tailwind:build` and `asset-map:compile` execute against
  `itkdev/php8.4-fpm:alpine` instead of the glibc image used for local
  development. `config/packages/symfonycasts_tailwind.yaml` pins the musl
  Tailwind binary under `when@prod`, and that binary cannot exec on glibc,
  so the 1.0.0 build died with `exec: tailwindcss-linux-x64-musl: not
  found` and published no release. The pin cannot be dropped in favour of
  the bundle's own platform detection: it reads PHP's build triplet out of
  `phpinfo()`, which the alpine image disables via `disable_functions`, so
  detection answers glibc in the one image where musl is required.
  `COMPOSE_SERVER_DOMAIN` is set because compose interpolates nginx's
  labels even when only `phpfpm` is run.

## [1.0.0] - 2026-09-17

### Added

- Updated `league/commonmark` to 2.10.1, clearing four advisories against
  2.8.2 — two high-severity denial-of-service parser bugs, a quadratic-time
  parse, and an `AttributesExtension` unsafe-link filter bypass via embedded
  control bytes. The `composer.json` floor moves to `^2.10.1` so a fresh
  install cannot resolve back into the affected range.
- Added woodpecker prod setup.
- Unified the Danish terminology for fetching an assistant. The
  interface previously alternated between "hjemtag", "eksportér",
  and "download" for one and the same action; every occurrence is
  now "download". The word "vidensopskrift" — which user testing
  showed non-technical curators did not recognise — becomes
  "vejledning", and the Viden tab heading follows. The front-page
  lead no longer says "resten" about the other municipalities, the
  "Mine assistenter" lead drops the unexplained "nye tilføjelser",
  the share wizard's paste-field help is cut to what it actually
  promises, and the responsibility notice names the guidance
  instead of "en opskrift på datagrundlaget". The front-page
  tagline and hero text are seeded settings as well as translation
  defaults, so both are updated. The JSON tab now offers OpenWebUI
  as the only download: curators were being asked to pick between
  five interchange formats with no basis for choosing, and the
  cross-format warning triangles that came with them went
  unexplained. Other formats stay reachable through the export
  route's `?format=` parameter, which is unchanged.
- Strengthened the status colour palette so alert, warning, and error
  boxes actually register. The four semantic token triples in
  `assets/styles/app.css` move their surfaces from the 50 step to 100
  and their borders from 200 to 500/600; every border now clears the
  3:1 non-text contrast bar against the page (amber tops out nearest,
  at 3.19:1, since a darker amber stops reading as amber), where the
  old 200-step borders sat at roughly 1.2–1.3:1 and let the whole box
  recede. Text keeps WCAG AA against its own surface throughout —
  6.37:1 at the tightest. The responsibility notice on the assistant
  share and edit pages moves off the neutral surface onto the warning
  palette, which is what user testing asked for: it carries a caution
  people need to read at exactly the moment they share. Colour values
  and colour classes only — no markup, ARIA role, border-width, icon,
  or typography change, so the `Alert` component's class contract and
  `AlertRenderTest` are untouched. The data-sensitivity pills on the
  assistant detail page reuse these tokens and deepen with them, which
  is the same signal at the same strength.
- Fourth data-sensitivity classification, "Ingen personoplysninger",
  for assistants that touch no personal data at all. User testing found
  curators with purely factual material had no honest option and were
  confused by the topmost one. `DataSensitivity::NoPersonal` is declared
  first, since declaration order is the order the wizard's radio cards
  render in and the scale now runs least-sensitive downward; the column
  is a nullable `STRING(32)` with `enumType`, so no migration is needed.
  "Almindelige personoplysninger" is rewritten to say what it
  covers — GDPR article 6, identifying but neither confidential nor
  sensitive — instead of the circular "almindelige personoplysninger og
  ikke-følsomt indhold". A new `DataSensitivity::example()` accessor
  adds a third line to every card naming concrete documents, so the
  choice can be made by recognition rather than by interpreting
  data-protection vocabulary.
- Rejected assistant configurations now explain themselves. The share
  wizard's paste field carries a minimal OpenWebUI export as its
  placeholder, so a first-time curator can see the expected shape before
  submitting anything. When a config is rejected,
  `ValidAssistantConfigValidator` states that plainly and names the
  accepted formats, instead of aggregating every adapter's schema errors
  — an almost-valid OpenWebUI export previously also drew "A Modelfile
  must contain a FROM instruction.", untranslated, about a format the
  curator never chose. The live check endpoint's errors now route
  through the `assistant_validation` catalogue too, which gains a
  Danish rendering of that Modelfile line for the case where somebody
  really is pasting one. What counts as valid is unchanged; only the
  reporting is.
- Kommune and Datafølsomhed facets on the catalogue. Kommune is the
  most obvious way in — a caseworker looks for what a comparable
  municipality already solved — and data sensitivity was shown on every
  assistant without being filterable. Both follow the recipe
  `CatalogCriteria` documents on itself (property, `fromRequest()`,
  `isEmpty()`, `activeFilters()`, `toQueryArray()`) and are appended
  after the tag facet, so existing chip positions and the tests pinning
  them are untouched. The kommune facet groups on the organisation name
  so URLs stay readable; assistants with no organisation fall out of the
  inner join and contribute to no bucket, matching how an untagged
  assistant behaves in the tag facet. The sensitivity facet keys on the
  enum's backing value and resolves each to its label for display, so
  nobody is shown `ordinary_personal`. Chip labels now pass through
  `|trans`, a no-op for the facets whose label is already the raw value.
- Removed the framework and data-sensitivity chips from the assistant
  detail header. Both values are already spelled out in the meta aside
  a few hundred pixels to the right, so the header was repeating itself.
  The assistant's own tags are unaffected and still render in the
  Beskrivelse tab.
- Admin screen at `/admin/assistants` listing the assistants an
  organisation has shared, with bulk reassignment of ownership from
  one member to another. Ownership is the `createdBy` blame stamp,
  which the edit/delete voter reads, so a transfer moves maintenance
  rights with it — letting a municipality keep hold of its assistants
  when the original curator leaves. Reachable for
  `ROLE_DOMAIN_MANAGER` and `ROLE_ADMIN`; a domain manager is scoped
  to the organisation their e-mail domain resolves to, a site admin
  picks which organisation to manage. The new-owner picker offers only
  approved users on that organisation's e-mail domains, for admins
  too, so an assistant cannot leave the municipality that shared it.
  Domain managers may now also edit and delete assistants belonging to
  their own organisation, via an additive
  `OrganizationAssistantVoter` that composes with the existing
  authorship rule rather than replacing it.

- Removed the placeholder "Modelkort" tab from the assistant
  detail page. The empty tab was never filled with substantive
  content, so its markup, translation keys, and integration-test
  case have been dropped. The detail page now renders three tabs:
  Beskrivelse / Viden / JSON, defaulting to Beskrivelse as before.
- Step 2 of the assistant create/edit wizard reorders its fields
  to narrate the assistant — identity (title, tagline) → what it
  does (description) → what it draws on (knowledge, language
  model + organisation) → how it's classified (tags, data
  sensitivity). Language model and organisation share a row on
  `md:` and up, stack below. The tagline field is now required
  (adds a `NotBlank` constraint under the `metadata` validation
  group and a matching `tagline_required` translation entry) so
  curators explicitly write the short one-line summary the
  catalog surfaces on card lists. Data sensitivity switches from
  a dropdown to a set of three rich radio cards — each card
  pairs the enum's short label with its longer descriptive
  copy, hovering a card darkens the border, and the selected
  card gets the primary border + `surface-2` background. The
  placeholder option is removed so the choice cannot ship
  silently on the first enum case.
- Data-sensitivity pill on the assistant details page is now
  colour-coded per classification, reusing the semantic alert
  palette so readers get an at-a-glance signal of how much care
  the assistant's knowledge base warrants: `ordinary_personal`
  renders as green (success), `confidential` as yellow (warning),
  and `sensitive_personal` as red (danger). The pill's tooltip
  (`assistant.dataSensitivity.description`) and label copy are
  unchanged; the empty-state pill deliberately stays on the
  neutral muted surface so "unknown" is not misread as "safe".
  The meta-sidebar sensitivity row stays plain text so the visual
  weight sits on the hero pill.
- Assistant cards on the catalog grid
  (`templates/components/Catalog/AssistantCard.html.twig`) and the
  frontpage rail (`templates/frontpage/index.html.twig`) now
  render the short one-line `tagline` field instead of the
  long-form `description`, falling back to `description` for
  seeded assistants that carry no tagline yet. Matches the
  pattern already used on the personal inventory
  (`templates/user/assistants.html.twig:32`). Purely visual —
  no entity or form change, and the details page
  (`_show_tab_beskrivelse.html.twig`) still renders the full
  description as before.
- Viden tab on the assistant details page replaces the Readme
  placeholder tab. Renders **Vidensopskrift** as the heading, a
  static intro paragraph clarifying that the recipe is shareable
  between kommuner while the underlying videns- og datafiler are
  not, and the assistant's `knowledgeDescription` beneath — using
  the same `paragraphs` + `whitespace-pre-wrap` treatment the
  Beskrivelse tab uses so line breaks stay visible. Empty
  knowledge descriptions fall back to a muted italic "Denne
  assistent har endnu ingen vidensopskrift." line so the heading
  and intro still render. The tab id changes from `readme` →
  `viden`
  in `AssistantController::DETAIL_TABS`; `?tab=readme` no longer
  resolves and silently falls back to Beskrivelse. Danish
  translations under `assistant.detail.tab` and
  `assistant.detail.viden.*` swap in accordingly; the unused
  `assistant.detail.readme.*` block is dropped.
- Assistant details page breadcrumb now renders the assistant's
  organisation name (or the "Ingen tilknyttet organisation"
  fallback copy when no organisation is attached) as the second
  segment, matching the eyebrow just below the breadcrumb. The
  segment previously rendered the raw translation identifier
  `assistant.detail.placeholder_origin` because the key was never
  wired to a real translation entry.
- Per-type colour treatment for the shared `Alert` component. Info,
  success, warning, and danger now each render a soft surface, a
  mid-weight border, and a WCAG-AA-legible text shade instead of the
  single neutral surface. Introduces four semantic token triples
  (`--color-{info,success,warning,danger}-{surface,line,ink}`) under
  the `@theme` block in `assets/styles/app.css`; success leans cool
  (emerald) so it reads as a status hue and not as a second brand-
  green accent. The `type` prop is renamed from `error` to `danger`
  to match the Info/Success/Warning/Danger design language, and the
  nine existing `type="error"` callsites are updated. ARIA behaviour
  is unchanged — warning/danger stay assertive (`role="alert"`),
  info/success stay polite (`role="status"`). A new
  `AlertRenderTest` pins each type's class triple + role so an
  accidental token rename can't silently drop the affordance.
- Domain-scoped registration notification. When a user completes
  email confirmation and transitions from `AwaitingEmailConfirmation`
  to `Pending`, every Approved user carrying `ROLE_DOMAIN_MANAGER`
  or `ROLE_ADMIN` whose own email is on the same domain now
  receives a notification alongside the existing site-wide admin
  recipient. Powered by a new
  `App\Notification\DomainRegistrationNotifier` and a new
  `UserRepository::findApproversForDomain()` lookup that filters
  to Approved managers-or-admins on the given domain. The mail
  reuses the admin-editable subject + body
  (`admin_notification_subject` / `admin_notification_body`) so a
  wording edit at `/admin/settings/email` updates both audiences
  at once; the help text under the "E-mailadresse" field on that
  page now names domain managers and administrators so the admin
  can see the full delivery set. `UserFixtures` gains a second
  `manager2@aarhus.dk` entry so tests exercise the "fan-out to
  every same-domain approver" path.
- Integration-test fixture consolidation pass. Eight test files
  under `tests/Integration/` (UserApprovalTest, UserRolesTest,
  UserRepositoryTest, SecurityControllerTest,
  UserCreateControllerTest, OrganizationControllerTest,
  SettingsControllerTest, UserMenuRenderTest) now look up their
  actor/subject users through the existing `UserFixtures::…_EMAIL`
  constants instead of calling `UserManager::createUser()` inline.
  Test count is unchanged (633 tests, 1920 assertions). The
  remaining inline `->createUser()` and `new User(...)` sites are
  legitimate keeps: `UserManagerTest` tests the create path
  itself, the last-admin guard tests in `UserRolesTest` /
  `UserApprovalTest` deliberately control the admin count, and
  `UserRepositoryTest` keeps a `(new User())` for its enum
  round-trip and a headless-user edge case. No new fixtures were
  needed — the existing `Roles::* × UserStatus` matrix covered
  every swap.
- Template audit-and-consolidation pass across `templates/` collapses
  ad-hoc markup onto the shared Twig component library. Five new
  components land: `Icon:Pencil` / `Icon:Upload` / `Icon:Trash`
  (one decorative inline SVG glyph per file, replacing the four
  hand-inlined `<svg>` blocks in `assistant/show.html.twig`,
  `assistant/_step_json.html.twig`, and `user/assistants.html.twig`),
  `Form:Textarea` (covers the five hand-rolled `<textarea>` fields
  across `admin/settings/{site,email}.html.twig` with `prose` and
  `mono` variants), `Form:Fieldset` (rounded panel + labelled
  legend, replacing the five identical fieldset+legend pairs in
  `admin/settings/email.html.twig` and now the single place a
  Stimulus controller can wrap an admin-form section),
  `Form:CsrfInput` (a hidden `_token` field wired to the
  `csrf-protection` Stimulus controller, replacing nine call sites and
  fixing a latent bug where `admin/settings/site.html.twig` and
  `user/assistants.html.twig` had lost the `data-controller` attribute
  and would submit stale tokens), and `Filter:Pill` (the four
  status-filter chips in `admin/user/list.html.twig` are now a shared
  component so a design change lands in one place). Four ad-hoc
  `role="alert"` `<div>`s in `registration/register.html.twig`,
  `admin/settings/{site,email}.html.twig`, and `admin/user/new.html.twig`
  become `<twig:Alert type="error">`, and the two open-coded
  `<h1 class="text-[clamp(…)] …">` in `registration/{register,pending}.html.twig`
  become `<twig:Heading level="1" size="lg">`. No behavior or visual
  change intended — the swap is markup-only.
- Password-reset flow at `/reset-password` (request → check-email → reset)
  built on `symfonycasts/reset-password-bundle`. The email subject + body
  are admin-editable under **Indstillinger → E-mail** with the same
  Markdown + `%token%` pipeline the other transactional messages use
  (`%name%`, `%email%`, `%brand_name%`, `%reset_url%`, `%expires_in%`) —
  no redeploy needed to update the copy. A new `PasswordResetNotifier`
  service owns token minting and delivery so the controller stays thin;
  the request-form response shape is identical for known and unknown
  addresses so the endpoint does not leak account existence. The login
  page carries a "Glemt password?" link, and the templates reuse the
  project's Twig components + Tailwind so the flow matches the rest of
  the security surface. Migration `Version20260708065059` adds the
  `reset_password_request` table.
- Detail page (`/assistant/{id}`) now renders the real
  organisation, tagline, and data-sensitivity values from the
  entity instead of the "kommer senere" placeholder copy that
  stood in while the metadata fields were being built. The
  data-sensitivity chip loses its muted-italic styling now that
  it carries a real classification, and its `title` attribute
  surfaces the enum case's descriptive text on hover. The
  "Godkendt til" sidebar item is retired for v1 (never
  wired to a real field). The Beskrivelse tab drops its
  "AI-foreslåede tags markeres, når feltet er tilføjet
  datamodellen." line. The sidebar's "Hjemtag konfiguration"
  button shrinks to the same compact `px-3 py-1.5 text-sm`
  sizing the "Gå til assistent" primary link uses on the
  personal inventory, so it stops overflowing the aside
  container; the redundant "Handlinger" aside box is removed
  now that the export lives in the detail aside. Migration
  `Version20260707131333` sets the organisation FK to
  `ON DELETE SET NULL` so admins can remove an organisation
  without seeded assistants' FKs blocking the delete.
- Edit-an-assistant wizard at `/assistant/{id}/edit`, reusing the
  create wizard's three-step shell (`json → metadata → receipt`).
  Access is gated by a new `EditAssistantVoter` that grants the
  assistant's original curator (`createdBy` blame) plus site admins;
  everyone else is denied. The draft is seeded from the persisted
  entity — the prefiller skips its own detection/organisation-default
  passes when the DTO carries `editingAssistantId`, so the curator's
  values are never clobbered by re-derivation from the acting user.
  New `AssistantEditor::update()` applies the DTO to the entity with
  the same normalisation rules as `AssistantCreator::create()`
  (canonical model, blank-to-null, ULID resolution). The step
  partials `_new_step_*.html.twig` are renamed to `_step_*.html.twig`
  to reflect their shared scope; a dedicated `edit.html.twig` shell
  drives the edit-specific copy (`assistant.edit.*` translation keys)
  and re-uses the three partials. The detail page grows a "Rediger
  assistent" affordance visible to the same audience the voter gates
  on. Migration `Version20260707111627` sets the `organization` FK
  to `ON DELETE SET NULL` so the admin can delete an organisation
  without hitting a constraint from the seeded assistants that
  reference it.
- Four new curator-facing metadata fields on the create wizard's step 2
  ([`assistant/new`](src/Controller/AssistantCreateController.php)) — `organization`
  (nullable `ManyToOne` to `Organization`), `tagline` (nullable string),
  `knowledgeDescription` (nullable text), and `dataSensitivity` (new
  `App\Enum\DataSensitivity` enum with `public / internal / personal /
  sensitive_personal`). The organization picker pre-fills from the
  logged-in user's e-mail domain via a new
  `OrganizationRepository::findOneByEmailDomain()` and a new
  `ModelMap::aliasesFor()`-style path in `AssistantDraftPrefiller`.
  `AssistantCreator::create()` folds blank strings on the three
  nullable columns to `null` on persist and ignores malformed /
  unknown organization ULIDs so tampered POSTs land safely. Fixtures
  seed realistic values for all four fields and every enum case is
  represented in the generated catalogue. Migration
  `Version20260707084856` adds the columns.
- The share wizard (`/assistant/new`) is now format-agnostic: its labels
  no longer say "OpenWebUI"/"JSON", the file picker accepts `.json` **and**
  `.modelfile` (so an Ollama Modelfile fits), and the live-validation
  endpoint detects the pasted format instead of assuming OpenWebUI. Each
  `FormatAdapter` declares `isExperimental()`; every format except
  OpenWebUI is flagged experimental (not manually verified), surfaced as a
  caution on the review step when importing such a format and as a marker
  next to its export button on the detail page (new `framework_experimental`
  Twig filter).
- Assistants can now be exported to four more formats and imported
  from them: an **AI-reolen native** JSON envelope (lossless), an
  **Ollama Modelfile** (text DSL), a **LibreChat preset**, and an
  **OpenAI Assistants** object — each a `FormatAdapter` that
  autoconfigures into `FormatAdapterRegistry` alongside the existing
  OpenWebUI adapter. Detection stays deterministic via per-adapter
  `#[AsTaggedItem(priority)]` (native → openai → librechat → openwebui
  → ollama). The JSON tab on the detail page offers a download link per
  target format. A new `JsonSchemaConfigValidator` base holds the shared
  syntax + JSON-Schema pipeline (OpenWebUI's validator is now a thin
  subclass), with new schemas under `config/schema/`.
- Base models are translated across systems via a curated
  `config/model_map.yaml` and a new `ModelMap` service: a source model
  is folded onto a neutral canonical id and rendered to each target's
  own model id on export. A model with no equivalent in a target (e.g.
  GPT-4o for Ollama) is passed through unchanged and flagged with a
  non-blocking warning — shown beneath the detail-page download links
  and in an `X-Export-Warning` response header — rather than silently
  swapped for a different model. The create wizard's language-model
  field is now a free-text input backed by a `<datalist>` of known
  models, defaulting to the detected model's canonical id. On top of
  that datalist, a Choices.js Stimulus controller
  (`assets/controllers/language_model_picker_controller.js`) turns the
  input into a tag-based combobox: the dropdown offers the union of
  the canonical shortlist and every `languageModel` value already in
  the catalogue (case-insensitive dedup, canonical spelling wins), and
  aliases from `model_map.yaml` fuel a fuzzy search so typing
  `openai/gpt` narrows the dropdown to `gpt-4o`. `AssistantCreator`
  folds aliases and legacy spellings to their canonical id on persist,
  keeping the catalogue's language-model facet deduplicated even when
  curators submit variant spellings. The pill shows whatever the
  curator typed. A new `AssistantRepository::persistedLanguageModels()`
  feeds the union list and a new `ModelMap::aliasesFor()` exposes the
  per-canonical alias tokens the picker searches on.
- Exports are validated against the target format's own rules before
  download, so a broken payload is never emitted; `FormatAdapter` gains
  `requiredCanonicalFields()` and the registry a `requiredForAnyExport()`
  union so the wizard can guarantee the fields any target needs
  ([#23](https://github.com/itk-dev/ai-reolen/issues/23)).
- Added prod config for tailwind bundle.
- Registration mail timing corrected. Signing up now fires
  exactly one transactional mail — the single-use email
  confirmation link. The two follow-up mails (moderator
  moderation notification + user welcome) that previously
  went out on the raw signup submit now dispatch from
  `App\Security\EmailConfirmation::consume()` instead, so
  nothing hits the moderator inbox and nothing welcomes the
  user until the address has been verified. Each new send
  keeps the same try/catch + logger-warning shape the signup
  path uses, so a transient SMTP failure never undoes the
  status transition or 500s the confirmation success page.
  Second clicks on an already-consumed confirmation link
  continue to render the `410 Gone` page and do not re-send
  the two follow-up mails
  ([#175](https://github.com/itk-dev/ai-reolen/issues/175)).
- The transactional-mail sender (`From:`) address is now
  deploy-time-only via the `MAILER_FROM` env var; the
  editable **Afsenderadresse** field on
  `/admin/settings/email` is removed. `SettingsManager::getSenderAddress()`
  reads `MAILER_FROM` and returns `null` when the env var is
  empty. All three registration notifiers (admin moderation,
  user welcome, email confirmation) already skip the send +
  log a warning when the sender resolves to `null`, so a
  fresh install with `MAILER_FROM=` unset no longer crashes
  the signup flow — the moderator queue still gates site
  access through `/admin/users`. Dropped:
  `SettingsManager::setSenderAddress()`,
  `validateSenderAddress()`, `applySenderAddress()`, the
  `sender_address` setting key, and the two integration tests
  that exercised the removed UI + persistence surface.
- Editor-experience upgrades on `/admin/settings/email`: every
  Markdown body field grows a **Markdown-oversigt** cheat-sheet
  link (opens
  <https://www.markdownguide.org/cheat-sheet/> in a new tab,
  `rel="noopener noreferrer"`) and a **Forhåndsvis** button that
  opens a modal showing the rendered email for the current
  subject + body. The modal reuses
  `App\Mail\EmailTemplateRenderer` — same pipeline the mailer
  runs through — so the preview is by construction what the
  recipient would see. Token substitution uses the acting
  admin's own `name` + `email` for the greeting slot, the
  current brand name for `%brand_name%`, and a synthetic
  `%approval_url%` / `%confirmation_url%` pointing at
  `/admin/users` and the frontpage respectively so URL tokens
  render as real links. The rendered HTML lives inside an
  `<iframe sandbox="allow-same-origin">` driven by `srcdoc` so
  admin-typed HTML can't script the admin UI or reach out over
  the network. A new POST endpoint
  `/admin/settings/email/preview` backs the modal: JSON in
  (`subject`, `body`, `_token`), JSON out (`subject`, `html`),
  admin-gated by the class-level `IsGranted` attribute and
  CSRF-protected against a dedicated
  `admin-settings-email-preview` intent so a stale carrier
  token can't invalidate an in-flight main-form submit. The
  new `email-preview` Stimulus controller mints tokens through
  the same shared `csrf-protection` helper the role-picker
  uses, so the double-submit-cookie handshake stays consistent
  ([#149](https://github.com/itk-dev/ai-reolen/issues/149)).
- [PR-162](https://github.com/itk-dev/ai-reolen/issues/162)
  Applied the OS2ai brand: green/charcoal/sand colour tokens replace
  the old teal/gold, self-hosted Inter + DM Serif Display replace the
  Google Fonts stack, and the header carries the brand as a wordmark.
- Primary site nav now carries only working destinations. **Del
  assistent** points at `/assistant/new`. **Mine assistenter**
  is a new page at `/mine/assistenter` (route
  `app_user_assistants`, gated to any authenticated user) that
  lists every assistant the current user is the `createdBy`
  blame for, ordered newest-first, rendered as a full-width
  responsive card grid (1/2/3/4 columns at sm/md/lg/xl). The
  page reuses the catalog's `<twig:Catalog:AssistantCard>` so
  the visual language of a card matches the catalogue. Empty
  state points the user at "Del assistent". Backed by a new
  `AssistantRepository::findCreatedBy(User)` query that binds
  the FK via `IDENTITY(a.createdBy) = :userId` with the ULID
  type, sidestepping DQL's entity-comparison ambiguity when
  the mapping uses `resolve_target_entities` on
  `UserInterface::class`. The **Favoritter** and **Samlinger**
  nav entries are dropped for now — they were placeholders
  pointing at `#` and come back with their features. `<twig:Nav:Link>`
  grows an `active` prop that emits `aria-current="page"` and
  a bolder ink treatment on the current route, so the active
  page is visible in the header at a glance
  ([#160](https://github.com/itk-dev/ai-reolen/issues/160)).
- The assistant details page (`/assistant/{id}`) is redesigned
  to match the ai-bibliotek mock. The `<twig:Layout:ContentWithAsides>`
  layout hosts a five-tab main column plus a sticky **Detaljer**
  meta aside and a **Handlinger** actions aside. Tabs
  (`Beskrivelse` / `Modelkort` / `Readme` / `Viden` / `JSON`)
  are anchor-based with the existing `<twig:Tabs>` component and
  driven by a whitelisted `?tab=` query string, so every tab is
  bookmarkable and SEO-friendly with no JavaScript required. The
  `JSON` tab's export button and the top-level "Hjemtag" action
  both point at the `app_assistant_export` download route (see the
  format-abstraction entry above). Fields the entity does not yet carry
  (`tagline`, origin organisation, `dataSensitivity`,
  `approvedFor`, `modelCard`, `readme`, `knowledgeRecipe`, AI-
  tag flag) render as muted italic placeholders with a tooltip
  explaining the status, so the layout is stable when the
  schema catches up. Version count, favourites, and collections
  are held back entirely — the meta row and action buttons only
  appear once the underlying feature ships.
  A new `App\Twig\TextExtension` exposes a `paragraphs` filter
  that splits a text blob on blank-line boundaries so the
  Beskrivelse tab renders each paragraph as its own `<p>` with
  `white-space: pre-wrap`
  ([#20](https://github.com/itk-dev/ai-reolen/issues/20),
  [#21](https://github.com/itk-dev/ai-reolen/issues/21)).
- `/assistant/new` is now a three-step wizard: **Indsæt JSON**
  → **Gennemgang** → **Kvittering**. The user pastes / uploads
  an OpenWebUI export on step 1, reviews auto-extracted metadata
  (title, description, language model, tags) on step 2, and
  lands on step 3 with a permalink to the freshly-persisted
  assistant. Metadata suggestions come from
  `App\Assistant\AssistantDraftPrefiller`, which detects the format
  and pulls values from the canonical model's name, description /
  system-prompt, base model, and tags — user edits on step 2
  are preserved on Back-then-edit-then-Next round trips (empty
  fields refill, non-empty stay). The wizard uses Symfony's
  built-in `AbstractFlowType` + `SessionDataStorage` so state
  carries between steps without any hand-rolled session
  plumbing; the Previous / Next / Finish navigator buttons come
  from `NavigatorFlowType` and self-hide via their `include_if`
  callbacks. On step 3, the Finish button doubles as "Del en
  til" — clicking it resets the flow and lands the user on a
  fresh step 1. Layout switches to `<twig:Layout:ThreeColumn>`
  with a new `<twig:StepRail>` component in the `start` slot
  and the responsibility notice in the `end` slot
  ([#20](https://github.com/itk-dev/ai-reolen/issues/20)).
- Single-use email-confirmation link as the mechanism that
  transitions a new user out of `UserStatus::AwaitingEmailConfirmation`.
  Self-signup now lands the user as `AwaitingEmailConfirmation`
  (no site access) instead of `Pending`, and a third
  transactional email — alongside the existing admin moderator
  notification and "thanks, awaiting approval" courtesy mail —
  carries an absolute URL pointing at the new public route
  `GET /auth/confirm-email/{token}`. Clicking the link consumes
  the token (single-use, 24 h TTL), flips the user's status to
  `Pending`, and renders a localised confirmation page; from
  there a moderator must approve the user through `/admin/users`
  before they gain site access. The token is a 32-byte base64url
  random string stored in a dedicated `cache.email_confirmation`
  pool, so token rows live independently of `cache.app`. A new
  `App\Security\EmailConfirmation` service owns issue + consume
  semantics (idempotency on stale status, defensive null on a
  missing user row), `App\Notification\EmailConfirmationNotifier`
  sends the link mail via the same `MAILER_FROM` resolution the
  other two notifiers use, and a `App\Controller\EmailConfirmationController`
  surfaces the public route with `410 Gone` on an unknown or
  already-consumed token. The `account.awaiting_email_confirmation`
  status message — previously unreachable from the live registration
  flow — now drives the failed-login response for a user who
  attempts to sign in before clicking the link
  ([#119](https://github.com/itk-dev/ai-reolen/issues/119)).
- Supported assistant frameworks are sourced from the registered
  format adapters (see the format-abstraction entry above). The
  `defaultFramework` `ChoiceType` on `/admin/organization/new` and
  `/admin/organizations/{id}/edit` lists the adapter labels; the
  stored value is the format id. A `App\Validator\SupportedFramework`
  constraint on `Organization::$defaultFramework` closes the
  entity-boundary path so fixtures and console writes can't leak an
  unregistered framework. The catalogue "Frameworks" facet renders
  labels through a `framework_label` Twig filter, falling back to the
  id for legacy rows whose format is no longer registered
  ([#154](https://github.com/itk-dev/ai-reolen/issues/154)).
- Public-signup allow-list now sources its domains from the
  `Organization.emailDomains` rows instead of the
  `REGISTRATION_ALLOWED_EMAIL_DOMAINS` env var. Adding a
  municipality through `/admin/organization` (or removing one)
  takes effect immediately — no redeploy, no config edit.
  `App\Security\AllowedEmailDomains` keeps its
  `contains()`/`all()` surface and now delegates to a new
  `App\Repository\OrganizationRepository::collectAllowedEmailDomains()`
  query that flattens, lowercases, dedupes, and drops blank
  entries. `OrganizationFixtures` grows an `Eksempel Kommune`
  row that owns `example.test` so the existing fixture users
  and the integration test suite continue to register
  successfully. The `REGISTRATION_ALLOWED_EMAIL_DOMAINS` env
  var is no longer read and can be removed from `.env`/`.env.test`
  ([#161](https://github.com/itk-dev/ai-reolen/issues/161)).
- The inline role-picker on `/admin/users` now mints its CSRF
  token at submit time through the bundled `csrf-protection`
  Stimulus helper, matching the project's stateless double-submit
  cookie pattern. The JSON fetch previously captured
  `csrf_token('admin-user-action')` server-side — which in
  stateless mode is the intent placeholder, not the real token —
  and submitted it raw, so the server rejected every dropdown
  change with HTTP 403 ("Sessionen er udløbet"). A hidden carrier
  `<form>` in the picker cell now hosts the
  `data-controller="csrf-protection"` input, and the role-picker
  controller calls `generateCsrfToken()` against it at submit
  time so the random token + matching `__Host-{intent}_{token}`
  cookie are paired correctly before the JSON body goes out.
- Dark footer with the OS2ai logo.
- Admin list pages (`/admin/users`, `/admin/organizations`) now
  fill the wide admin container instead of the narrow `max-w-4xl`
  column. The user list in particular has six columns after the
  role-picker landed and needed the headroom; the organization
  list matches for consistency. Detail / form pages keep their
  existing widths.
- Inline role promotion on `/admin/users`. The user list grows a
  **Rolle** column and a per-row dropdown that posts to a new
  `POST /admin/users/{id}/role` JSON endpoint. Three transitions
  are wired: promote to manager, promote to admin, remove all
  permissions. Authorisation centralises in
  `App\Security\Voter\ManageUserVoter`, which now supports three
  new attributes (`PROMOTE_TO_MANAGER`, `PROMOTE_TO_ADMIN`,
  `DEMOTE_USER`) and enforces the headline rule the issue closes:
  **a manager must never edit an admin** — not even within their
  own email domain — and only an admin may mint a new admin. The
  rule applies at both layers: the dropdown's "Promote to Admin"
  option only renders when the actor holds `ROLE_ADMIN`, and the
  endpoint denies forbidden combinations with `403` even when
  hand-crafted. The role transitions go through a new
  `App\Security\UserRoles` service that refuses to demote the
  last remaining admin (surfaced as HTTP `409` with the
  `last_admin` error code via a `LastAdminException`), so a site
  can't be accidentally locked out. The endpoint validates a
  fresh `admin-user-action` CSRF token in the JSON body (`403`
  on reject), returns `422` for malformed role payloads, and
  `200` with `{ role, label }` on success. The Stimulus
  `role-picker` controller fires the POST, swaps the inline role
  label on success, and surfaces server errors via an
  `aria-live="polite"` feedback span next to the dropdown
  ([#148](https://github.com/itk-dev/ai-reolen/issues/148)).
- Three reusable page layout components under
  `templates/components/Layout/`. Site header, `<main>`, and
  footer now share the wide container (`max-w-wide`, ≈ 1600px
  from the mocks' `--container-wide`) so all three chrome edges
  align — matching the prototype's `index.html`, which puts
  `container-wide` on all three. `<twig:Layout:SingleColumn>`
  renders a stacked flow inside that wide `<main>` (no extra
  horizontal constraint of its own — inner components like
  `<twig:Hero>` or `<twig:Box>` carry whatever narrower
  max-width they need) and is adopted on the frontpage as the
  reference consumer. `<twig:Layout:ThreeColumn>` (slots `start`,
  `main`, `end`) and `<twig:Layout:ContentWithAsides>` (slots
  `main`, `meta`, `actions` with sticky asides) cover the
  catalogue and detail surfaces. The previous ad-hoc
  `max-w-[1600px]` literals on the header, footer, and `<main>`
  are replaced by the token-backed `max-w-wide` utility. The
  narrow `--container-narrow` token (≈ 1180px) is defined in
  `@theme` for future use but no current page applies it,
  mirroring the prototype where `.container` is defined but
  unused. Grid CSS lives in `assets/styles/app.css` under
  `@layer components`. Three pages adopt the new layouts as
  reference consumers: the frontpage (`SingleColumn`),
  `/assistant/new` (`SingleColumn`), `/search` (`ThreeColumn`
  with filters in the `start` slot and an empty `end` slot
  reserved for a future context rail), and `/assistant/{id}`
  (`ContentWithAsides` with the title + description in `main`,
  runtime details in `meta`, and tags in `actions`)
  ([#144](https://github.com/itk-dev/ai-reolen/issues/144)).
- Unified CSRF protection on the stateless double-submit cookie
  pattern. Every hand-rolled form intent —
  `assistant-create`, `register`, `admin-organization-delete`,
  `admin-user-action`, `admin-settings-site`, and
  `admin-settings-email` — is now listed under
  `framework.csrf_protection.stateless_token_ids`, so Symfony's
  `SameOriginCsrfTokenManager` validates the token against the
  `__Host-csrf` cookie instead of a session-bound token. The
  matching `<input type="hidden" name="_token">` fields gain
  `data-controller="csrf-protection"` so the bundled Stimulus
  controller mirrors the cookie value into the field on submit.
  Symfony Form-built submissions (`UserCreateType`,
  `OrganizationType`, `ProfileType`, `AssistantType`) were
  already stateless via the `submit` intent; this aligns the
  hand-rolled paths with that approach, removing the
  session-bound CSRF code path from the project entirely
  ([#111](https://github.com/itk-dev/ai-reolen/issues/111)).
- New `hero_text` site setting and `SettingFixtures` class.
  The frontpage hero's lead paragraph is now sourced through
  `SettingsManager::getHeroText()` and exposed as the
  `hero_text` Twig global by `BrandExtension`, falling back
  to the `frontpage.hero.lead` translation when no row is
  stored. The `/admin/settings/site` form gains a "Hero-tekst"
  textarea below the existing brand fields. A new
  `App\DataFixtures\SettingFixtures` writes a baseline value
  for every key `SettingsManager` exposes today —
  `admin_recipient`, the three brand fields, `hero_text`,
  and the four email subject/body fields — through the typed
  setters so the fixture stays in lock-step with the form
  path
  ([#127](https://github.com/itk-dev/ai-reolen/issues/127)).
- The `/admin/settings/email` form now validates the
  `admin_recipient` and `sender_address` fields together before
  persisting either, so a submission that pairs a valid recipient
  with an invalid sender (or vice versa) is rejected as a whole
  and neither row is written. `SettingsManager` grows pure
  `validateAdminRecipient()` and `validateSenderAddress()` helpers
  alongside the existing `apply*()` shortcuts so multi-field
  callers can do validate-all-then-persist
  ([#132](https://github.com/itk-dev/ai-reolen/issues/132)).
- Transactional email sender (`From:`) is now admin-editable
  at `/admin/settings/email` next to the existing
  "Modtager" field. `SettingsManager::getSenderAddress()`
  reads the new `sender_address` setting and falls back to
  the `MAILER_FROM` env var when unset, mirroring the
  `BRAND_NAME` pattern. Both
  `App\Notification\AdminRegistrationNotifier` and
  `App\Notification\RegistrationConfirmationNotifier`
  resolve the sender at send time so admin edits take
  effect immediately, and log + skip when both setting
  and env are empty so a half-configured mailer doesn't
  crash registrations. Accepts both bare e-mails
  (`noreply@…`) and the display-name form (`"AI Reolen
  <noreply@…>"`) — same shape Symfony's
  `Address::create()` parses
  ([#132](https://github.com/itk-dev/ai-reolen/issues/132)).
- `assets-build` Taskfile target to compile the Tailwind CSS bundle
  (supports `-- --watch` and `-- --minify`); `site-install` now reuses it.
- Enlarged the catalogue search bar to match the design — it now uses a
  larger input and button. Adds a `size` prop to the `Form:TextInput`
  component and an `lg` size to `Form:Button`. The `Form:Button` no longer
  shrinks or wraps its label below its content width when placed beside a
  growing field, so the "Søg" label stays fully visible.
- Made the catalogue right-hand sidebar box headings more pronounced by
  rendering them in the dark ink colour instead of muted grey, matching the
  design.
- Tightened the spacing across the catalogue layout — the gap between the
  three columns, between stacked sidebar boxes, and within the results
  column — for a more compact arrangement matching the design.
- Catalogue right-hand sidebar and one-per-row results. The `/search`
  page becomes a three-column layout — facet rail, results, and a new
  sidebar with an "Aktive filtre" box (active-filter chips, moved out of
  the results column, with an empty state), a "Seneste søgninger" box
  listing the user's recent searches as re-runnable links (per-session,
  deduplicated, most-recent-first), and an informational "Klar til
  hjemtagning" box. Result cards now render one per row, restructured
  to lead with the title (large display serif), a language-model kicker,
  the description, and the framework plus tags as pills
  ([#19](https://github.com/itk-dev/ai-reolen/issues/19)).
- Branded HTTP 401 and 403 pages for the firewall entry
  points. `App\Security\UnauthorizedEntryPoint` now renders
  `templates/security/unauthorized.html.twig` ("Log ind
  påkrævet" + login / register CTA) instead of the empty
  401 that made Firefox fall back to its built-in error UI.
  `App\Security\AccessDeniedHandler` (wired on the `main`
  firewall as `access_denied_handler`) renders
  `templates/security/access_denied.html.twig` ("Adgang
  nægtet" + link back to the frontpage) instead of the
  default blank Symfony 403. Both templates extend the
  public base layout so brand chrome stays present, and the
  two cases (no authentication vs. wrong role) intentionally
  render differently
  ([Symfony docs](https://symfony.com/doc/current/security/access_denied_handler.html)).
- Catalogue result ordering. The `/search` page gains a "Sortering"
  box in the filter column, above the filters, offering newest, oldest,
  recently-updated, and name (A–Å / Å–A) orderings, defaulting to
  newest-first. The chosen ordering travels in the `?sort=` URL
  parameter, combines with the active search and facet filters (each
  form mirrors the other's state as hidden inputs, so changing the sort
  preserves the filters and toggling a facet preserves the sort), and
  survives pagination. Changing the order auto-submits (Stimulus, with a
  no-JS fallback) and returns to page 1
  ([#19](https://github.com/itk-dev/ai-reolen/issues/19)).
- Transactional registration emails. After a successful
  `/register` submission, two emails are dispatched: an admin
  notification to the moderator inbox (the existing
  `admin_recipient` setting) and a "thanks, awaiting approval"
  confirmation to the user. Both messages render from
  admin-editable Markdown templates persisted on the
  `Setting` entity (`admin_notification_subject` /
  `_body`, `registration_confirmation_subject` / `_body`),
  with `%token%` placeholders resolved by a new
  `App\Mail\EmailTemplateRenderer`. The `/admin/settings/email`
  page grows two new fieldsets to edit subject + body per
  email, with the available placeholders listed inline.
  Transport failures are logged and swallowed so a mailer
  outage doesn't undo a successful registration. Partially
  closes #119 — the one-time login link lands in a follow-up
  ([#119](https://github.com/itk-dev/ai-reolen/issues/119)).
- Self-service profile edit page at `/profile/edit`. Any
  authenticated user — regardless of role — can update their
  own display name. The subject is always read off the
  security context, never a path parameter, so the route
  can't be used to address another user's row. The
  controller stays thin: hands the submission to a new
  `App\Form\ProfileType` and persists through the existing
  `UserManager::updateUser($email, name: ...)` path so
  password / role / status mutations stay in one place. The
  `UserMenu` "Redigér profil" item lights up automatically
  now that the route exists
  ([#128](https://github.com/itk-dev/ai-reolen/issues/128)).
- Catalogue free-text search and tag filtering. The `/search`
  page gains a search box that matches the assistant title and
  description (case-insensitive) and a "Tags" facet alongside
  the existing Sprogmodel and Rammeværk facets; all combine with
  each other and free-text search, and every active filter is
  reflected in the URL and as a removable chip. Ticking a facet
  checkbox submits the filter form immediately (Stimulus, with the
  Apply button as the no-JS fallback). The frontpage search box now
  submits to the catalogue, so a query from the homepage lands on
  `/search` with results. Tags are now a
  relational `Tag` entity joined to `Assistant` many-to-many
  (replacing the previous JSON column), recorded in
  [ADR 008](docs/adr/008-tags-as-relational-entity.md)
  ([#18](https://github.com/itk-dev/ai-reolen/issues/18)).
- Anti-flood protection on both anonymous form surfaces. The
  registration form gets a per-IP rate limiter (env-tunable
  via `REGISTRATION_RATE_LIMIT_PER_IP`, defaults to 10/day) and
  a system-wide ceiling (`REGISTRATION_RATE_LIMIT_SYSTEM`,
  defaults to 100/day), both wired through Symfony's Rate
  Limiter component and backed by a dedicated
  `cache.rate_limiter` pool. Either limiter rejecting the
  request renders HTTP 429 with a localised
  `register.error.rate_limited` message. The login form gets
  Symfony Security's built-in `login_throttling` on the `main`
  firewall (5 attempts / 15 minutes per `<IP, username>`),
  which surfaces the bundled Danish "for mange mislykkede
  loginforsøg" message on the login page. Registration is
  also now idempotent on a duplicate e-mail — the response
  matches a fresh signup so a probe can't learn whether the
  address is taken
  ([#107](https://github.com/itk-dev/ai-reolen/issues/107)).
- `UserFixtures` now seeds five extra accounts on top of
  `alice@example.test` / `bob@example.test` so every
  `App\Security\Roles` value and every `App\Enum\UserStatus`
  case is represented out of the box: `admin@aarhus.dk`
  (`ROLE_ADMIN`, `Approved`), `manager@aarhus.dk`
  (`ROLE_DOMAIN_MANAGER`, `Approved`),
  `pending@aalborg.dk` (`Pending`), `awaiting@aalborg.dk`
  (`AwaitingEmailConfirmation`), and `blocked@odense.dk`
  (`Blocked`). All share the plain `password` for
  paste-friendly local login. Each address is exposed as a
  public constant on `UserFixtures` so downstream lookups
  don't repeat the string
  ([#126](https://github.com/itk-dev/ai-reolen/issues/126)).
- Admin-page roundup: `/admin/users` gains a primary
  **"Opret bruger"** button (gated on `ROLE_ADMIN`) and a full
  HTML create form at `/admin/users/new` backed by a non-mapped
  `UserCreateType` that delegates to
  `UserManager::createUser()` for hashing + persistence. Both
  `/admin/users` and `/admin/organization` lists are migrated
  from ad-hoc `<ul>` cards to the shared `<twig:Table>`
  component family. `/admin/settings` is split into
  `/admin/settings/site` (brand name / tagline / initials) and
  `/admin/settings/email` (admin notification recipient); the
  bare `/admin/settings` route redirects to the site page, and
  a new `<twig:Tabs>` component (plus the
  `admin/settings/_tabs.html.twig` partial) renders the
  switcher between the two surfaces with the active tab
  marked `aria-current="page"`. Brand identity now resolves
  through `SettingsManager::getBrandName()` / tagline /
  initials with `BRAND_*` env vars as the fallback default; an
  `App\Twig\BrandExtension` exposes the resolved values as the
  `brand_name` / `brand_tagline` / `brand_initials` Twig
  globals. Every `/admin/**` page renders against a light
  pink (`bg-admin-bg`) body background so operators see at a
  glance that they're in the back office
  ([#124](https://github.com/itk-dev/ai-reolen/issues/124)).
- The `/assistant/new` upload widget now pretty-prints the
  JSON it drops into the editable textarea (two-space indent,
  preserved newlines) so the operator can read and edit the
  config before submit. The server-side path is unchanged —
  Doctrine's `JSON` column type decodes the form value to an
  array and re-encodes it without whitespace, so whatever
  indentation the user typed (file upload, paste, hand-edit)
  collapses to minified JSON on disk
  ([#101](https://github.com/itk-dev/ai-reolen/issues/14)).
- `Assistant.source_config` JSON column for storing the
  uploaded assistant config (reduced to its format's cleaned
  model; see the entries above), plus a create form at
  `/assistant/new` with a file-upload field that AJAX-validates
  the JSON before submit and writes the result into an editable
  textarea (users can also paste/type JSON directly). A shared
  `App\Validator\OpenWebUiConfigValidator` service hosts the
  validation pipeline (JSON syntax + a temporary slow-validation
  scaffold for UI testing); the uploaded file itself is never
  persisted, only the parsed JSON reaches the database. The
  create form picks up the project's default-deny gating: any
  authenticated user can submit, no admin role required
  ([#14](https://github.com/itk-dev/ai-lib/issues/14)).
- Higher-contrast zebra striping on the shared `<twig:Table>`
  component. Even rows now use a new dedicated
  `--color-row-alt` (`#f1f3f5`) design token instead of the
  near-white `--color-surface-2`, so admin lists read as
  alternating light-gray / white rather than a wall of white.
  Odd rows are made explicit `bg-bg` so the stripes survive
  tinted backgrounds. `--color-surface-2` is left alone — it
  remains the project's hover-state tone
  ([#130](https://github.com/itk-dev/ai-reolen/issues/130)).
- `app:user:update` console command that updates an existing
  user's display name, roles, and / or lifecycle status. Each
  field is optional — omitting it leaves the value untouched.
  `--role` may be repeated to set multiple roles and replaces
  the current role list wholesale; unknown role identifiers
  and unknown status strings are rejected with a clear message.
  Sits behind a new `UserManager::updateUser()` service method
  ([#122](https://github.com/itk-dev/ai-reolen/issues/122)).
- `task site-install` learned an opt-in `RESET=1` parameter
  (`RESET=1 task site-install`) that drops and recreates the
  application database before running migrations, so a fresh
  install on top of an existing schema doesn't fail with "table
  already exists". The default invocation stays non-destructive.
- Foundation for outbound transactional mail: `symfony/mailer`
  installed and wired to the existing `mail` container (Mailpit) via
  `MAILER_DSN=smtp://mail:1025` in `.env`, with a deploy-overridable
  `MAILER_FROM` sender. `.env.test` pins `null://null` so the test
  suite never hits a real transport. Generic `setting` table (`name`
  unique, `value` nullable text) backs an `App\Settings\SettingsManager`
  service exposing a typed `getAdminRecipient()` / `setAdminRecipient()`
  pair. A new admin-only page at `/admin/settings` (`ROLE_ADMIN`, CSRF
  on POST, 422 on invalid email) lets administrators set the recipient
  for registration-related notifications; the user-menu surfaces it
  under **Administration → Indstillinger** when the acting user is a
  site admin. Mail templates and the actual signup/admin-notification
  /one-time-login flows land in follow-up work
  ([#119](https://github.com/itk-dev/ai-lib/issues/119)).
- Data fixtures now assign a creating user (round-robin
  `alice@example.test` / `bob@example.test`) to each seeded assistant and
  organization, so the created-by/modified-by blame relation is exercised by
  local-development data.
- Integrated [`itk-dev/entity-bundle`](https://github.com/itk-dev/entity-bundle)
  as the shared entity foundation. New `App\Entity\AbstractEntity` extends the
  bundle's `AbstractITKDevEntity`, giving every domain entity a ULID primary
  key plus timestamps, created-by/modified-by blame, archivability, and
  anonymization status. `User`, `Assistant`, and `Organization` now extend it
  (integer ids replaced by ULIDs), are marked `#[Auditable]`, and `User`'s PII
  is annotated with `#[Anonymize]`. All bundle features are enabled except soft
  delete (`config/packages/itk_dev_entity.yaml`); `damienharper/auditor-bundle`
  is wired for the audit log. Admin route `{id}` requirements (`User`
  approve/block, `Organization` edit/delete) accept `Requirement::ULID`
  instead of `\d+`. Records the decision in
  [ADR 007](docs/adr/007-entity-foundation-entity-bundle.md)
  ([#104](https://github.com/itk-dev/ai-lib/issues/104)).
- Admin CRUD for `Organization` at `/admin/organization` (list,
  create, edit, delete) per ADR 003. Built with `symfony/form`
  (newly added dependency) + raw Twig templates, multi-value
  email-domain field via a textarea with a `CallbackTransformer`,
  and Danish UI copy under `admin.organization.*`. Auth gating
  intentionally deferred to a follow-up issue
  ([#76](https://github.com/itk-dev/ai-reolen/issues/76)).
- Default-deny `access_control` rule on the `main` firewall: every
  route now requires `IS_AUTHENTICATED_FULLY` except the
  `PUBLIC_ACCESS` allow-list (`/login`, `/logout`, `/register`,
  `/register/pending`). Anonymous visitors hitting a gated route
  get the standard Symfony redirect to `/login`, with the
  originally requested URL preserved so they land back on the
  page after signing in
  ([#97](https://github.com/itk-dev/ai-reolen/issues/97)).
- Authenticated-user dropdown menu in the top nav. The user's
  display name is the trigger; the menu groups into a **Bruger**
  section (Edit profile, Log out) and a role-gated
  **Administration** section (Administrér organisationer,
  Administrér brugere). Each item is only rendered when both the
  acting user holds the gating role and the target route is
  registered, so the menu degrades gracefully on branches where
  later admin PRs haven't landed yet. ARIA "Menu Button" pattern
  via a new Stimulus controller — Escape and outside-click close
  the menu
  ([#108](https://github.com/itk-dev/ai-reolen/issues/108)).
- Shared admin-list Table Twig component family
  (`templates/components/Table.html.twig` +
  `templates/components/Table/Head|Body|Row|HeadCell|Cell.html.twig`)
  that renders a semantic `<table>` inside a horizontal-scroll
  wrapper, with zebra-striped body rows and an `align` prop on
  the cell components for right-flushed action columns. First
  consumer migrations land alongside their respective PRs
  ([#112](https://github.com/itk-dev/ai-reolen/issues/112)).
- Anonymous self-signup at `/register`. The route is
  open to unauthenticated visitors; submissions go through
  `App\Security\Registration` which validates the email format,
  checks the right-hand-side domain against an env-backed allow-list
  (`REGISTRATION_ALLOWED_EMAIL_DOMAINS`, comma-separated; default
  empty so production must opt-in by setting the var, with
  `example.test` set in `.env.test` for the test suite), requires
  matching password confirmation, and creates the `User` with
  `status = Pending`. The user is redirected to `/register/pending`
  ("thanks, awaiting approval") and cannot sign in until a domain
  manager approves them. CSRF-protected via Symfony's
  `csrf_token('register')` helper. Localised in the existing
  `messages` domain
  ([#62](https://github.com/itk-dev/ai-reolen/issues/62)).
- Admin user-management surface at `/admin/users` per ADR 006. Lists
  users scoped by role — `ROLE_ADMIN` sees every user, a
  `ROLE_DOMAIN_MANAGER` sees only users whose email domain matches
  their own. Optional `?status=pending|approved|blocked` filter for
  the approval queue (`/admin/users/pending` redirects to
  `?status=pending`). Per-row Approve / Block buttons are gated by
  the `ManageUserVoter` from #84 (same-domain check) and the new
  `App\Security\UserApproval` service flips the status. The list
  view uses a new repository finder
  `UserRepository::findVisibleTo()`, scoped against the actor's
  role + email domain via the `EmailDomain` helper. CSRF-protected;
  `back` parameter on the action forms only honours
  `/admin/users…` URLs
  ([#64](https://github.com/itk-dev/ai-reolen/issues/64),
  [#85](https://github.com/itk-dev/ai-reolen/issues/85)).
- `ROLE_DOMAIN_MANAGER` + `ROLE_ADMIN` role identifiers
  (`App\Security\Roles`), `role_hierarchy` wiring in `security.yaml`
  so `ROLE_ADMIN` implies `ROLE_DOMAIN_MANAGER`, and a
  domain-scoped `ManageUserVoter` that grants the `MANAGE_USER` /
  `APPROVE_USER` / `BLOCK_USER` attributes when the acting user is a
  domain manager in the subject's email domain (or a site-wide
  admin). Lays the authorisation foundation for the admin approval
  queue (#64) and the scoped user-management list view (#85) per
  ADR 006
  ([#84](https://github.com/itk-dev/ai-reolen/issues/84)).
- `User.name` (display name) and `User.status` (lifecycle enum:
  `pending | approved | blocked`) per ADR 006, plus the
  `App\Enum\UserStatus` PHP enum. `UserManager::createUser()` now
  requires `name` and accepts an optional `status` (default
  `Approved` for the console / fixture path; the registration flow
  in #62 will pass `Pending`). The `app:user:create` console command
  takes a third `name` argument; fixtures seed Alice + Bob with
  display names. Schema is added via a single migration that
  backfills any existing rows with `name = ''` and
  `status = 'approved'`
  ([#45](https://github.com/itk-dev/ai-reolen/issues/45),
  [#83](https://github.com/itk-dev/ai-reolen/issues/83)).
- Added tailwind build to site-install task.
- Shared `Heading` Twig component (`templates/components/Heading.html.twig`)
  that renders `<h1>`–`<h6>` with size tokens centralised in one place.
  Refactors `assistant/show.html.twig`, `security/login.html.twig`,
  and the `PageHeader`, `Hero`, `EmptyState`, `Filter/Rail`
  components to use it
  ([#92](https://github.com/itk-dev/ai-reolen/issues/92)).
- `App\Security\AccountStatusChecker` implementing
  `UserCheckerInterface` — gates the login flow so any `User` whose
  `status` is not `Approved` is rejected before the password is
  verified, with distinct localised messages per state
  (`account.awaiting_email_confirmation`, `account.pending`,
  `account.blocked`) rendered in the `security` translation domain.
  Wired on the `main` firewall via `security.yaml`'s `user_checker:`
  key
  ([#63](https://github.com/itk-dev/ai-reolen/issues/63),
  [#103](https://github.com/itk-dev/ai-reolen/issues/103)).
- Shared `DescriptionList` Twig component family
  (`templates/components/DescriptionList/List.html.twig` +
  `templates/components/DescriptionList/Item.html.twig`) for
  label/value pairs. The assistant detail's runtime attribute grid
  adopts it
  ([#94](https://github.com/itk-dev/ai-reolen/issues/94)).
- ADR `005-organization-entity` recording the decision to introduce
  `Organization` as a first-class entity with name, multiple emails,
  and a default framework — no language-model field, with the
  `User → Organization` reference and admin CRUD tracked as separate
  issues
  ([#65](https://github.com/itk-dev/ai-reolen/issues/65)).
- `Organization` Doctrine entity (name, list of email domains,
  default framework), repository, migration, and
  `OrganizationFixtures` seeding three baseline kommuner (Aarhus,
  Aalborg, Odense). First step of ADR 005 — `User → Organization`,
  CRUD, and assistant autocomplete land in follow-up issues
  ([#75](https://github.com/itk-dev/ai-reolen/issues/75)).
- `User.name` (display name) and `User.status` (`UserStatus` enum:
  `awaiting_email_confirmation | pending | approved | blocked`)
  fields
  ([#45](https://github.com/itk-dev/ai-reolen/issues/45),
  [#83](https://github.com/itk-dev/ai-reolen/issues/83),
  [#103](https://github.com/itk-dev/ai-reolen/issues/103)).
- `ROLE_DOMAIN_MANAGER` + `ROLE_ADMIN` role identifiers
  (`App\Security\Roles`), `role_hierarchy` wiring in `security.yaml`
  so `ROLE_ADMIN` implies `ROLE_DOMAIN_MANAGER`, and a
  domain-scoped `ManageUserVoter` that grants the `MANAGE_USER` /
  `APPROVE_USER` / `BLOCK_USER` attributes when the acting user is a
  domain manager in the subject's email domain (or a site-wide
  admin).
  ([#84](https://github.com/itk-dev/ai-reolen/issues/84)).
- Test-env `framework.exceptions` override so
  `NotFoundHttpException` logs at `info` instead of `error`, keeping
  PHPUnit output clean when a test deliberately asserts a 404
  ([#95](https://github.com/itk-dev/ai-reolen/issues/95)).
- Shared `Alert` Twig component (`templates/components/Alert.html.twig`)
  for flash messages and inline errors. `type` (`success` | `error` |
  `warning` | `info`) drives the ARIA role; the login error block
  adopts it
  ([#93](https://github.com/itk-dev/ai-reolen/issues/93)).
- Catalogue listing page with filters
  ([#15](https://github.com/itk-dev/ai-reolen/issues/15)).
- Initial Symfony 8 application scaffold on the ITK Dev Docker
  `symfony-8` template (phpfpm 8.4, nginx, MariaDB, Mailpit, Traefik),
  including dev dependencies for coding standards (`php-cs-fixer`,
  `twig-cs-fixer`) and composer normalization
  ([#1](https://github.com/itk-dev/ai-reolen/issues/1)).
- Architecture Decision Records under `docs/adr/` with index and the
  first ADR `001-tech-stack-docker-symfony`
  ([#11](https://github.com/itk-dev/ai-reolen/issues/11)).
- `CLAUDE.md` with project-level operating instructions for AI agents
  — stack, structure, execution policy, branching, commits, CHANGELOG,
  ADR conventions, translations, brand env vars, Tailwind rebuild
  notes, and the domain glossary
  ([#5](https://github.com/itk-dev/ai-reolen/issues/5)).
- Human-facing `README.md` rewritten around the AI Bibliotek catalog
  — project description, status banner, feature list, tech stack,
  Task-based local development workflow, contributing pointers, and
  prototype references
  ([#28](https://github.com/itk-dev/ai-reolen/issues/28)).
- `CONTRIBUTING.md` documenting branching, Conventional Commits,
  coding standards, changelog expectations, and the pull-request
  workflow
  ([#9](https://github.com/itk-dev/ai-reolen/issues/9)).
- Project license declared as **MPL-2.0** — full `LICENSE` text at
  the repo root, `composer.json` `license` field updated from
  `proprietary` to `MPL-2.0`, and ADR `004-project-license-mpl-2`
  recording the rationale
  ([#32](https://github.com/itk-dev/ai-reolen/issues/32)).
- `Taskfile.yml` exposing common developer commands via `task --list`
  (compose helpers, composer, console, coding-standards family) with
  README updates documenting `task` as a host requirement
  ([#29](https://github.com/itk-dev/ai-reolen/issues/29)).
- Frontend tooling: Tailwind CSS via `symfonycasts/tailwind-bundle`,
  Symfony AssetMapper, and Stimulus via `symfony/stimulus-bundle`,
  with base Twig layout (`templates/base.html.twig`), asset
  entrypoints (`assets/app.js`, `assets/styles/app.css`), Tailwind v4
  design tokens (`@theme`), and ADR `002-frontend-tooling`
  ([#38](https://github.com/itk-dev/ai-reolen/issues/38)).
- PHPUnit test harness with a 100 % coverage gate enforced in CI via
  `rregeer/phpunit-coverage-check`
  ([#31](https://github.com/itk-dev/ai-reolen/issues/31)).
- README refocused as human-facing project documentation: project purpose,
  tech stack, and local development bootstrap. Developer command reference
  moved to `CLAUDE.md` (and later `CONTRIBUTING.md`, tracked in #9).
- ITK Dev Docker setup via the `symfony-8` template (phpfpm 8.4, nginx, MariaDB, Mailpit).
- Dev dependencies for coding standards and composer normalization:
  `ergebnis/composer-normalize`, `friendsofphp/php-cs-fixer`, `vincentlanglet/twig-cs-fixer`.
- Project README with local development instructions.
- Frontend tooling: Tailwind CSS (via `symfonycasts/tailwind-bundle`),
  Symfony AssetMapper, and Stimulus (via `symfony/stimulus-bundle`).
  Decision recorded in [ADR 002](docs/adr/002-frontend-tooling.md).
  ([#14](https://github.com/itk-dev/ai-reolen/issues/14), [#16](https://github.com/itk-dev/ai-reolen/issues/16)).
- Base Twig layout (`templates/base.html.twig`) and frontend asset
  entrypoints (`assets/app.js`, `assets/styles/app.css`).
- Placeholder frontpage at `/` (`App\Controller\FrontpageController`)
  previewing the AI Bibliotek design with hardcoded sample data
  (hero, search box, sample-assistant rail, "Sådan virker det" steps),
  site chrome (header with brand + nav, footer), the Stimulus
  `nav_toggle_controller` driving the mobile menu, and a
  `block-on-label` GitHub Action providing a per-PR merge gate
  ([#40](https://github.com/itk-dev/ai-reolen/issues/40)).
- User authentication: `User` Doctrine entity (email, hashed password,
  roles), `UserRepository` (with `PasswordUpgraderInterface`), the
  `UserManager` service that hides persistence + hashing, form-login
  firewall + `/login` + `/logout`, fixtures for two baseline users
  (`alice@example.test`, `bob@example.test` — password `password`),
  console commands `app:user:create` and `app:user:change-password`,
  and end-to-end functional + unit tests
  ([#2](https://github.com/itk-dev/ai-reolen/issues/2)).
- PHPUnit suite split into `unit` (no database) and `integration` (full
  kernel) testsuites under `tests/Unit/` and `tests/Integration/`, with
  transactional database isolation per integration test via
  `dama/doctrine-test-bundle`. The integration suite uses a dedicated
  `tests/bootstrap_integration.php` that builds the schema from ORM
  metadata and loads baseline `UserFixtures` once before any test;
  DAMA's per-test transaction rolls back mutations so the baseline
  persists. The default `tests/bootstrap.php` is minimal and is used
  by `task test-unit`. `task test-unit` and `task test-integration`
  expose the suites individually.
- Reusable Twig form components under `templates/components/Form/`:
  `Form/Label`, `Form/Input`, and `Form/Button` (with `variant` and
  `size` props for future styling variants). The `/login` template
  consumes them instead of inlining the input/label/button markup.
- Site chrome (header with brand + nav, footer) in
  `templates/base.html.twig`, with the Fraunces/Geist font stack
  preloaded from Google Fonts.
- Tailwind v4 design tokens (`@theme` in `assets/styles/app.css`)
  matching the prototype palette and typography.
- Stimulus controller `nav_toggle_controller` driving the mobile
  navigation menu.
- GitHub Action `block-on-label` that fails the check while a
  `do-not-merge` label is applied to a pull request, providing a
  per-PR merge gate for dependencies (e.g. another PR that must land
  first).
- `LICENSE` file at repo root containing the full Mozilla Public License 2.0 text.
- Project license declared as **MPL-2.0** (Mozilla Public License 2.0); the
  `license` field in `composer.json` updated from the Symfony skeleton
  default `proprietary` to the SPDX identifier `MPL-2.0`.
- `Taskfile.yml` exposing common developer commands via `task --list`
  (compose helpers, composer, console, coding-standards family).
- README documents [Task](https://taskfile.dev) as a host requirement and
  uses `task` targets in the *Common commands* section.
- `docs/adr/` with index (`docs/adr/README.md`) and ADR `001-tech-stack-docker-symfony`
  documenting the choice of Symfony 8 on the ITK Dev Docker `symfony-8` template.
- `CONTRIBUTING.md` documenting branching, Conventional Commits, coding
  standards, changelog expectations and the pull-request workflow.
- GitHub issue template `.github/ISSUE_TEMPLATE/issue.md` and pull-request
  template `.github/PULL_REQUEST_TEMPLATE.md`, each with a human-facing
  "Resume" / checklist section followed by an "AI specificities" detail
  block so other agents can continue work from a structured brief
  ([#69](https://github.com/itk-dev/ai-reolen/issues/69)).
