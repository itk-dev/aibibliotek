# CLAUDE.md

Operating instructions for Claude Code (and other AI agents) working in this
repository. Audience is the agent, not human contributors — for human-facing
documentation see [README.md](README.md).

When the instructions here conflict with the user's global `~/.claude/CLAUDE.md`,
this file wins — project-specific rules override the global defaults.

## Project overview

`ai-reolen` is a shared catalog of AI assistants for the Danish public sector.
The application is a Symfony 8 web app built on the ITK Dev Docker
development setup.

## Tech stack

- **PHP 8.4** running under `phpfpm` (Symfony 8 skeleton, PSR-4 `App\\` at `src/`).
- **Nginx** in front of `phpfpm`, served via the shared **Traefik** proxy at
  `https://ai-reolen.local.itkdev.dk`.
- **MariaDB** for persistence.
- **Mailpit** for outbound mail capture at
  `https://mail-ai-reolen.local.itkdev.dk`.
- **ITK Dev Docker** template `symfony-8` provides the container orchestration.

## Project structure

```text
bin/                Symfony console entry
config/             Symfony configuration (bundles, packages, routes, services)
public/             Web root (public/index.php)
src/                Application code (PSR-4 namespace App\)
assets/             Frontend entry points (placeholder until a stack is picked)
.docker/            nginx config and templates
.github/workflows/  CI workflows (do NOT edit locally — see "Workflows" below)
docs/adr/           Architecture Decision Records (see "ADRs")
```

## Execution policy

All language/build tooling runs inside containers. Never invoke `php`,
`composer`, `node`, `npm`, `npx`, `prettier`, or similar on the host.

Preferred order:

1. `task <name>` — the project's `Taskfile.yml` is the entry point for
   everyday commands. Run `task --list` to see what's available.
2. `task compose -- <args>` / `task compose-exec -- <args>` — pass-through
   helpers when no dedicated target exists.
3. `itkdev-docker-compose <command>` — for cross-project ITK Dev tooling
   not wrapped by the project Taskfile (e.g. `traefik:start`).
4. `docker compose --profile dev run --rm <service> <args>` — direct fallback
   for the dev-only tooling (`prettier`, `markdownlint`) when going around
   the Taskfile is justified.

## Common commands

```sh
# Lifecycle
task start                          # pull, up, composer install
task down                           # tear the stack down

# Composer / PHP / Symfony console
task composer -- <command>          # e.g. task composer -- require foo/bar
task compose-exec -- phpfpm php <command>
task console -- <command>           # e.g. task console -- cache:clear

# Coding standards (check / apply pairs)
task coding-standards-php-check
task coding-standards-php-apply
task coding-standards-twig-check
task coding-standards-twig-apply
task coding-standards-yaml-check
task coding-standards-yaml-apply
task coding-standards-markdown-check
task coding-standards-markdown-apply
task coding-standards-composer-check
task coding-standards-composer-apply

# Run every check at once
task coding-standards-check

# Tests
task test                           # PHPUnit, no coverage
task test-coverage                  # PHPUnit + Xdebug coverage, enforces 100% gate
```

The coverage gate is **100%** and is enforced by the `Tests` GitHub
Actions workflow on every pull request — see `.github/workflows/tests.yaml`.

Run the matching check before committing changes in that area. For
commands without a dedicated task, fall back to `task compose -- <args>`
or `itkdev-docker-compose <args>`.

## Coding standards

Config files live at the repo root:

- `.php-cs-fixer.dist.php` — PHP CS Fixer (Symfony ruleset).
- `.twig-cs-fixer.dist.php` — Twig CS Fixer.
- `.prettierrc.yaml` — Prettier (YAML, CSS/SCSS, JS).
- `.markdownlint.jsonc` + `.markdownlintignore` — Markdown lint.

These come from the `symfony-8` template — don't edit them without a reason.
If a project-specific override is needed, override via the template's
documented mechanism (e.g. `.php-cs-fixer.php` next to `.php-cs-fixer.dist.php`).

### Tests are not modified without approval

Do **not** edit, rename, delete, or skip files under `tests/` (or any other
test files) without explicit user approval — even when a failure looks like
a stale assertion. If a change you're making appears to require test
updates, stop and describe to the user, briefly:

- Which test files / test methods need to change.
- What the change is (assertion update, fixture change, new case, removal).
- Why it's needed (production behavior changed, contract widened, etc.).

Wait for the user to approve before touching the files. The 100% coverage
gate (see "Common commands") means test edits have real consequences;
the user decides whether the production change or the test is wrong.

## Coding practices

Style conventions code in this project follows, on top of the linter
rules in "Coding standards" above.

### Defer to symfony.com/doc when implementing Symfony features

When you add or change functionality that lives on top of a Symfony
component — controllers, routing, security, forms, validation,
Doctrine integration, console commands, messenger, mailer,
translation, asset mapping, Twig extensions, etc. — open the
relevant chapter on <https://symfony.com/doc> first and base the
implementation on the approach the docs show. The docs name the
component, demonstrate the idiom, and link the configuration
references; following them keeps the code in step with the
framework instead of drifting into bespoke shapes that look
reasonable but miss built-in conventions.

When the docs offer more than one path (e.g. PHP attributes vs.
YAML config, MapEntity vs. ParamConverter), pick the one that
matches what's already in this codebase. If nothing comparable
exists yet, prefer the most recent idiom shown in the docs — the
attribute-driven, autoconfigured, autowired style.

Cite the relevant doc URL in the PR description for any change
that introduces a Symfony-component idiom for the first time, so
reviewers can compare the implementation against the source.

### Controllers stay thin

Controllers handle routes and template/response rendering only — no business
logic. Push logic into a service class. A controller action looks like:
inject service → call service method → return `render()` / `Response` /
`RedirectResponse`.

**Do not add PHPDoc to controllers.** The class name, route attribute,
action name, parameter types, and return type already describe what an
action does; class- and method-level docblocks duplicate that. Push the
explanatory prose into the (fully documented) service the controller
delegates to. If a controller is so unusual that it needs a docblock to
explain itself, that's the signal it's doing too much.

### Service classes are fully documented

Every service class method (public, protected, private) carries a PHPDoc block
with a one-line summary, a description of intent, `@param` per parameter,
`@return`, and `@throws` for every exception that can be raised.

### Docblocks describe code, not project context

Docblocks (PHPDoc, Twig file comments, JSDoc, etc.) describe what the code
does and how to use it. They do **not** carry project history. Do not
reference:

- ADRs (`per ADR 003`, `see docs/adr/...`).
- Issue numbers (`(issue #76)`, `[#62]`).
- PR numbers (`see PR #109`).
- Follow-up issues, parent issues, milestones, or sprint codes.

Project context — *why* the work was done, what ticket drove it, which ADR
governs it — belongs in the PR description, the commit message body, or the
CHANGELOG entry. Those surfaces have a natural shelf life; a docblock is
read for years and shouldn't anchor a future reader to a closed ticket.

If the *why* matters to a reader of the code, the *why* goes inline in
plain language: "the entity constructor requires every field, so the form
supplies an `empty_data` factory" belongs in the docblock. "Per ADR 003"
does not.

Applies uniformly across controllers (which carry no docblock at all),
services, entities, forms, Twig template comments, and tests.

### Test methods carry a one-line intent comment

Each `public function test…` opens with a single-line comment that
names what the test asserts, starting with `// Tests …`,
`// Ensures …`, or `// Verifies …`. Pick whichever verb reads
naturally for the assertion in question.

- One line, terse — not a docblock, not a paragraph.
- Placed immediately above the method declaration.
- If a block-level docblock already exists on the method (e.g. to
  explain *why* the test matters in context), keep it and put the
  one-liner beneath it. The docblock serves the *why*; the one-liner
  names the *what*.

The comment is for a reader scanning the file's table of contents
without reading method bodies. Matches the convention applied across
every test file on the project.

### Twig components — consult first, don't reinvent

Before writing raw HTML for a common UI shape, check
`templates/components/` for an existing component. Prefer `<twig:…>`
over inlining new markup, even when the raw form is only three or four
lines — the point is that a design change or accessibility patch then
happens in one place instead of five.

| Shape | Component |
| --- | --- |
| Solid / ghost / link button | `Form:Button` (variant `primary` / `ghost` / `link`) |
| Icon-only outlined button | `Form:IconButton` (tone `primary` / `danger` / `neutral`) |
| Heading (h1–h6) | `Heading` (size + optional `muted`) |
| Eyebrow / small kicker | `Eyebrow` (accent, decorative rule) or `Heading size="caption[-lg]"` (plain label) |
| Text input / textarea / select / label / checkbox | `Form:TextInput`, `Form:Textarea`, `Form:Select`, `Form:Label`, `Form:Checkbox` |
| Fieldset + legend | `Form:Fieldset` |
| Hidden CSRF token | `Form:CsrfInput` |
| Flash / inline alert | `Alert` |
| Data table | `Table` + `Table:*` children |
| Filter link pill / remove chip | `Filter:Pill`, `Filter:Chip` |
| Inline SVG glyph | `Icon:*` (one file per glyph) |

When a swap requires a small additive change to a component — a new
prop, a new variant, `{{ attributes }}` pass-through — **make the
additive change rather than skip the call site**. Additive changes
default to a no-op for existing consumers and carry no regression
risk. Only skip when the additive change would materially widen the
API (new pseudo-selector matrix, behavior split, incompatible slot
shape), and say so explicitly in the PR body.

Before opening a template-touching PR, grep the diff for raw
`<button`, `<h[1-6]`, `<textarea`, `<label`, `<input` (outside a form
theme), and `<div ... role="alert">` — each should either be a
`<twig:…>` call or carry a comment naming why it stays raw.

## Workflows

The `.github/workflows/*.yaml` files are mirrored from
[`itk-dev/devops_itkdev-docker`](https://github.com/itk-dev/devops_itkdev-docker)
and carry a `Do not edit this file!` header. If a workflow needs to change,
open a PR upstream rather than patching locally.

## Branching and PRs

- Base branch for feature work: **`develop`**. `main` is the release/stable line.
- Branch name: **`feature/issue-<n>-<short-slug>`** (e.g. `feature/issue-5-claude-md`).
- One issue per branch where possible. Reference the issue number in the
  branch name and PR.
- PR target: **`develop`**.
- A PR must:
  - Link the issue with `Fixes #<n>` (or `Closes #<n>` for non-bug issues).
    Use `Refs #<n>` when coverage is partial.
  - Pass all required CI checks before merging.
  - Carry a `CHANGELOG.md` update under `## [Unreleased]` for any user-visible
    change.
- If a PR carries the `do-not-merge` label, the PR description must spell
  out **what blocks the merge and why** (e.g. waiting on upstream change,
  dependent PR, unresolved decision). Keep this up to date — remove or
  rewrite the block reason as blockers resolve.

### PR description style

Write the human-facing description so a reviewer can scan it in 30 seconds.
The detailed AI brief lives in the template's `# Details - AI specificities`
section and may stay as long as it needs to be.

- **Bullets are one or two lines each.** If a bullet needs a paragraph,
  it's probably two bullets — split it.
- **Name only the primary file(s) tied to the feature or bug.** Don't
  enumerate every file the diff touches; the file list is in the diff
  itself.
- **Include the Leantime link if the linked issue has a milestone with
  one.** Fetch the milestone via
  `gh api repos/itk-dev/ai-reolen/milestones/<n>`, look for an `LT: <url>`
  line in the milestone description, and add the URL to the PR description
  under a short `#### Links to issues` heading. If the milestone has no `LT:` line,
  or the PR has no linked issue / no milestone, skip the section — don't
  fabricate one.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` new feature
- `fix:` bug fix
- `docs:` documentation only
- `chore:` tooling, build, deps, repo housekeeping
- `refactor:` code change that neither adds a feature nor fixes a bug
- `test:` tests only

Keep subject lines under ~70 characters. Use the body for the *why*.

## CHANGELOG

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Add an entry to `## [Unreleased]` under the right section (`Added`, `Changed`,
`Fixed`, `Removed`, `Deprecated`, `Security`) for every meaningful change.

**Pre-release rule:** while the project has no tagged releases yet,
*everything* is `Added` — there is no prior released version for a
change to be `Changed`, `Fixed`, `Removed`, `Deprecated`, or `Security`
relative to. Keep those sections empty (or omit them) and fold the
entry into `Added`, even when the work edits or replaces material that
already exists in `[Unreleased]`. Before adding to any non-`Added`
section, check `git tag` (or the GitHub releases page) and confirm at
least one release exists; if none does, use `Added`. Once the first
release is cut, the standard Keep a Changelog sections apply normally
from the next `[Unreleased]` onward. See PR #57 for the prior
consolidation that established this convention.

## GitHub issue types and labels

Every issue **must** have its native **issue type** set to one of:

- **Bug** — something is broken.
- **Feature** — new user-visible capability.
- **Task** — everything else: chores, tooling, documentation, infrastructure,
  refactors, ADRs, etc.

Documentation-only work is tracked as a **`Task`** type plus the
`documentation` **label**. The type classifies the nature of the work,
labels add orthogonal context.

The current `gh` CLI (≤ 2.92) does not expose `--type`. To set a type,
fall back to the REST API (`PATCH /repos/{owner}/{repo}/issues/{n}` with
`type=<Name>`) when available, otherwise ask the user to set it in the
UI. Labels can always be set with `gh issue create --label`.

When creating an issue, use the repository's issue template at
`.github/ISSUE_TEMPLATE/issue.md`. Preserve its structure — every heading
and HTML comment marker stays in its original order — and fill each
section from the available context. Pass it via `gh issue create
--body-file` (or `--body` with the rendered content) rather than hand-
rolling a description.

## Pushing

SSH keys aren't available to the Claude session. Push one-off via HTTPS:

```sh
git push https://github.com/itk-dev/ai-reolen.git HEAD:<branch>
```

Do not change the `origin` remote URL — SSH is wanted for normal use outside
Claude.

## ADRs

Architectural decisions are recorded as ADRs in `docs/adr/`. Create and manage
them via the `itkdev-adr` skill (see issue #11). Open an ADR for decisions
that:

- Change the runtime architecture (storage, integrations, deployment).
- Choose between two viable options with non-trivial trade-offs.
- Establish a convention other contributors must follow.

Small implementation choices belong in code review, not in an ADR.

Do not include a "Follow-up Actions" (or similarly named) checklist
inside an ADR. Track follow-up work as GitHub issues and reference the
ADR from each issue, not the other way around. The ADR records the
decision; the issues track the work derived from it. The one-time
cleanup of existing sections is tracked in #44.

## Domain glossary

- **Assistant** — a configured AI persona/prompt bundle that can be exported,
  shared, and re-imported.
- **Catalog** — the searchable collection of assistants surfaced by the app.
- **OpenWebUI export format** — the JSON schema used by
  [OpenWebUI](https://openwebui.com/) for importing/exporting assistants;
  the canonical interchange format for this project.
- **Share/upload flow** — the moderated path by which a user submits an
  assistant to the catalog (metadata + review).
- **Tags / categories** — taxonomy applied to assistants for filtering and
  discovery.
- **Moderation** — validation and review of submitted assistants before they
  appear in the catalog.

## When in doubt

- Prefer an existing pattern in the codebase over inventing a new one.
- For non-trivial decisions, write an ADR or ask the user — don't silently
  pick.
- For destructive git operations (`reset --hard`, `push --force`, branch
  deletion), stop and ask the user — these are deny-listed globally for good
  reason.
