# Contributing to ai-reolen

Thanks for contributing. This document describes how we branch, commit,
review, and ship changes in this repository. It is aligned with the
[ITK Dev GitHub guidelines](https://github.com/itk-dev/itk-dev) and
applies to every change — code, configuration, and documentation.

## Core rules

1. **Never commit directly to `develop` or `main`.** All changes go
   through a pull request from a feature branch.
2. **Every change starts from a GitHub issue.** Open one first if it
   does not exist, and reference it from the branch and PR.
3. **Every PR updates `CHANGELOG.md`** under `[Unreleased]`.
4. **PRs close their issues** with a `Closes #NN` (or `Fixes #NN`)
   trailer in the PR description.

## Branching

- Base branch: `develop` (the default branch).
- `main` mirrors the last released state and is updated from `develop`.
- Create one branch per issue, named:

  ```text
  feature/issue-{number}-{short-description}
  ```

  Examples:

  - `feature/issue-9-contributing-guidelines`
  - `feature/issue-45-fix-pagination-bug`
  - `feature/issue-78-update-api-endpoints`

  Use kebab-case for the description and keep it short (3–6 words).

## Commits

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
<type>: <short description>

[optional body explaining the why]

[optional footer with trailers, e.g. Refs #NN, Co-authored-by: …]
```

Allowed types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`,
`chore`, `perf`, `ci`, `build`.

Guidelines:

- Use the imperative mood ("add", not "added").
- Keep the subject under ~70 characters.
- Reference the issue in the body or footer (`Refs #NN`) when useful.
- Pair commits to single, reviewable units of work.
- When a commit was co-authored (for example with an AI assistant),
  add a `Co-authored-by:` trailer for each co-author.

Example:

```text
feat: add language switcher component

Adds a dropdown that lets visitors switch between supported locales
and persists the choice in a cookie.

Refs #42
Co-authored-by: Claude Opus 4.7 <noreply@anthropic.com>
```

## Coding standards

Run the project's standards before opening a PR. The same checks are
enforced in CI under `.github/workflows/`.

```sh
# PHP
itkdev-docker-compose vendor/bin/php-cs-fixer fix

# Twig
itkdev-docker-compose vendor/bin/twig-cs-fixer lint

# Markdown
docker compose --profile dev run --rm markdownlint markdownlint '**/*.md'

# YAML
docker compose --profile dev run --rm prettier '**/*.{yml,yaml}' --write

# Composer
itkdev-docker-compose composer normalize
itkdev-docker-compose composer validate --strict
```

If a `Taskfile.yml` is present, prefer the equivalent `task` targets
(`task coding-standards-php-apply`, etc.).

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/)
and the project uses [Semantic Versioning](https://semver.org/).

Add an entry under `## [Unreleased]` in the appropriate section:

- `Added` — new features.
- `Changed` — changes to existing behaviour.
- `Deprecated` — soon-to-be removed features.
- `Removed` — removed features.
- `Fixed` — bug fixes.
- `Security` — security fixes.

Write the entry for the reader, not the committer: describe the
user-visible change, not the implementation.

## Pull requests

### Before opening

- [ ] Branch is up to date with `develop`.
- [ ] `CHANGELOG.md` is updated under `[Unreleased]`.
- [ ] All CI checks pass locally (see [Coding standards](#coding-standards)).
- [ ] Commits follow Conventional Commits.

### Description template

```markdown
## Summary

Brief description of what this PR does and which issue it addresses (#NN).

## Changes

- Bullet list of notable changes.

## Test plan

- Steps a reviewer can follow to verify the change.
- Expected outcome for each step.

Closes #NN
```

### Review and merge

- At least **one approving review** is required.
- All GitHub Actions checks must pass.
- Prefer **squash merge** so the resulting commit on `develop` matches
  the PR title (Conventional Commits format).
- Delete the feature branch after merge.

## Reporting issues

Open a GitHub issue with a clear title, a short description of the
problem or proposal, and (when relevant) steps to reproduce or
acceptance criteria. Add a label that matches the work type
(`enhancement`, `bug`, `documentation`, …).
