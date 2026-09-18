# AI Bibliotek (ai-reolen)

A shared catalog of AI assistants for the Danish public sector. `ai-reolen`
lets contributors export, share, search, and import assistants — using the
OpenWebUI JSON format as the interchange — and provides moderation and
metadata around what ends up in the catalog.

## Tech stack

- [Docker](https://www.docker.com/) and Docker Compose v2
- [`itkdev-docker-compose`](https://github.com/itk-dev/devops_itkdev-docker) on your `PATH`
- A working [Traefik](https://github.com/itk-dev/devops_itkdev-docker?tab=readme-ov-file#traefik) reverse proxy
- PHP 8.4 / Symfony 8
- Nginx + Traefik
- MariaDB
- Mailpit (outbound mail capture, local only)
- ITK Dev Docker development setup (`symfony-8` template)
- Taskrunner [Task](https://taskfile.dev/) (`Taskfile.yml`)

> **Status:** early development. The application is being scaffolded — see the
> [Base setup milestone](https://github.com/itk-dev/ai-reolen/milestone/1) and
> [open issues](https://github.com/itk-dev/ai-reolen/issues).

The platform is built up across milestones:

- **Catalog** — browse and list shared AI assistants with metadata.
- **Search & filtering** — full-text search, filter by tags/category, and sorting.
- **Assistant details** — full metadata and a readable configuration preview.
- **JSON export** — download an assistant as OpenWebUI-compatible JSON.
- **JSON import (share/upload flow)** — upload/paste OpenWebUI JSON to share an
  assistant, with validation and optional moderation.
- **User management** — login, roles/permissions, and profiles.
- **OpenWebUI tag/workflow** — AI-generated tags and OpenWebUI round-trip.

## Local development

Run `task` (or `task --list`) to see every available target.

## Common commands

```sh
# Run Composer inside the phpfpm container
task composer -- <command>

# Run a Symfony console command
task console -- <command>

# Apply PHP coding standards
task coding-standards-php-apply

# Lint Twig templates
task coding-standards-twig-check

# Format YAML
task coding-standards-yaml-apply

# Lint Markdown
task coding-standards-markdown-check

# Normalize composer.json
task coding-standards-composer-apply

# Run every coding-standards check
task coding-standards-check

# Run the PHPUnit test suite
task test

# Run PHPUnit with coverage and enforce the 100% gate
task test-coverage
```

## Frontend assets

The project uses [Tailwind CSS](https://tailwindcss.com/) on top of
Symfony's [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html),
with [Stimulus](https://stimulus.hotwired.dev/) for behaviour. There is
no Node toolchain — the Tailwind binary is managed by
[`symfonycasts/tailwind-bundle`](https://github.com/SymfonyCasts/tailwind-bundle).
See [ADR 002](docs/adr/002-frontend-tooling.md) for the rationale.

```sh
# One-time: download the Tailwind binary (also runs lazily on first build)
itkdev-docker-compose php bin/console tailwind:build

# Build the compiled stylesheet
itkdev-docker-compose php bin/console tailwind:build

# Watch source files and rebuild on change (development)
itkdev-docker-compose php bin/console tailwind:build --watch

# Compile and version the full importmap + assets (production)
itkdev-docker-compose php bin/console asset-map:compile

# Inspect what AssetMapper sees
itkdev-docker-compose php bin/console debug:asset-map
```

> **Heads-up:** there is no live Tailwind watcher running by default, and
> `cache:clear` does **not** rebuild the stylesheet. After editing a
> template that introduces a utility class not already in use (e.g.
> `pt-2`, `grid-cols-1`), run `tailwind:build` — or keep a
> `tailwind:build --watch` terminal open while you style.

Source files live under [`assets/`](assets):

- `assets/app.js` — JavaScript entrypoint, boots Stimulus.
- `assets/styles/app.css` — Tailwind entrypoint (`@import "tailwindcss";`).
- `assets/controllers/` — Stimulus controllers, auto-registered by
  filename (`nav_toggle_controller.js` → `data-controller="nav-toggle"`).

For one-off commands without a dedicated task, fall back to the underlying
tools, e.g. `docker compose --profile dev run --rm prettier <args>` or
`itkdev-docker-compose <args>`.

### Page layouts

Every page picks one of three reusable layout components under
[`templates/components/Layout/`](templates/components/Layout). The
site chrome — header, `<main>`, and footer — shares the wide
container (`max-w-wide`, ≈ 1600px) so the three edges line up. Each
layout component then owns the inner shape of its main content.

- **`<twig:Layout:SingleColumn>`** — the default. Renders a stacked
  flow inside the wide `<main>`. No extra horizontal constraint of
  its own — inner components (`<twig:Hero>`, `<twig:Box>`, forms,
  etc.) own whatever section-level max-width they need. Slot:
  `content`. Use for the frontpage, login / register, simple admin
  forms, error pages.
- **`<twig:Layout:ThreeColumn>`** — broad middle with a narrow left
  column and an optional right rail. Slots: `start`, `main`, `end`.
  Stretches to fill `<main>` and collapses to a single stacked
  column below 768px. Use for the catalogue / search surfaces.
- **`<twig:Layout:ContentWithAsides>`** — wide main content with two
  sticky right-side asides. Slots: `main`, `meta`, `actions`.
  Stretches to fill `<main>`. Use for assistant detail pages and
  similar surfaces with secondary panels alongside the primary
  content.

Container width comes from the `--container-wide` token in
`assets/styles/app.css` (exposed as the `max-w-wide` utility by
Tailwind v4's `@theme`). The `--container-narrow` token is also
defined for future use on sections that need a tighter reading
width, but no current page applies it. The grid CSS lives in the
same file under `@layer components`.

> The commands below describe the intended ITK Dev standard setup. The actual
> Docker + Symfony scaffolding is added in
> [#1 Set up Docker + Symfony](https://github.com/itk-dev/ai-reolen/issues/1);
> until that is merged, some commands will not yet be available.

### Requirements

- [Docker](https://www.docker.com/) and the
  [ITK Dev Docker setup](https://github.com/itk-dev/devops_itkdev-docker)
  (`itkdev-docker-compose`, Traefik)
- [Task](https://taskfile.dev/installation/)

### Getting started

```sh
# Clone the repository
git clone https://github.com/itk-dev/ai-reolen.git
cd ai-reolen

# List all available tasks
task

# Install site
task site-install

# Open the site
task open
```

The site is served through Traefik on a `*.local.itkdev.dk` domain (the exact
URL is printed by the start task).

### Creating the first user

```sh
# Option A — load the local-dev fixtures (alice + bob, password `password`)
task console -- doctrine:fixtures:load -n

# Option B — create a single user explicitly
task console -- app:user:create alice@example.test secret

# Change an existing user's password
task console -- app:user:change-password alice@example.test newsecret
```

Then sign in at `/login`.

## Testing

Tests live under `tests/` (PSR-4 namespace `App\Tests\`) and run with
[PHPUnit](https://phpunit.de/) inside the `phpfpm` container. Code
coverage is enforced at **100%** in CI — pull requests that drop coverage
below that threshold fail the `Tests` workflow.

```sh
# Run the full suite (no coverage)
task test

# Run the suite under Xdebug coverage and enforce the 100% gate
task test-coverage
```

`task test-coverage` runs PHPUnit with `XDEBUG_MODE=coverage`, writes a
Clover report to `coverage/clover.xml`, and then runs
[`rregeer/phpunit-coverage-check`](https://github.com/richardregeer/phpunit-coverage-check)
against the report. The same two steps run in the `Tests` GitHub Actions
workflow on every pull request; a coverage figure below 100% fails the
build.

## Entities

Domain entities are built on
[`itk-dev/entity-bundle`](https://github.com/itk-dev/entity-bundle) through the
project base class `App\Entity\AbstractEntity`. Extending it gives an entity a
ULID primary key plus the shared cross-cutting concerns the catalogue applies
everywhere: created/updated timestamps, created-by/modified-by blame,
archivability, and anonymization status. Which features are active is set once
in `config/packages/itk_dev_entity.yaml` (all enabled except soft delete — the
project archives rather than soft-deletes; see
[ADR 007](docs/adr/007-entity-foundation-entity-bundle.md)).

To add a new entity, extend the base class and opt into the two per-entity
concerns:

```php
use App\Entity\AbstractEntity;
use Doctrine\ORM\Mapping as ORM;
use ITKDev\EntityBundle\Audit\Attribute\Auditable;
use ITKDev\EntityBundle\Privacy\Attribute\Anonymize;
use ITKDev\EntityBundle\Privacy\Strategy;

#[ORM\Entity]
#[Auditable] // opt into the audit log (writes to <table>_audit)
class Example extends AbstractEntity
{
    #[ORM\Column(length: 255)]
    #[Anonymize(strategy: Strategy::Redact)] // mark personal data for GDPR erasure
    private string $fullName = '';
}
```

A subclass that declares its own constructor must call `parent::__construct()`
so the ULID is assigned. The identifier is a `Symfony\Component\Uid\Ulid`
(`getId()` returns it), and route parameters that carry an entity id use
`Requirement::ULID`.

## References

- **Estimation note:** <https://itk-dev.github.io/research-projects/projects/ai-bibliotek/estimeringsnotat>
- **Prototype & design direction:** <https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/>

### Prototype routes

| View              | Route                                                                                                               |
|-------------------|---------------------------------------------------------------------------------------------------------------------|
| Home / front page | [`#/`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/)                        |
| Login             | [`#/login`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/login)              |
| Catalog / search  | [`#/search`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/search)            |
| Assistant details | [`#/assistant/:id`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/assistant/) |
| Upload / import   | [`#/upload`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/upload)            |
| My assistants     | [`#/uploads`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/uploads)          |
| Favorites         | [`#/favorites`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/favorites)      |
| Collections       | [`#/collections`](https://itk-dev.github.io/research-projects/projects/ai-bibliotek/mocks/index.html#/collections)  |

> The prototype is a client-side mock (data stored locally in the browser),
> not production code.

## License

`ai-reolen` is licensed under the [Mozilla Public License 2.0](LICENSE).
See [ADR 004 — Project license: MPL-2.0](docs/adr/004-project-license-mpl-2.md)
for the reasoning behind the choice.
