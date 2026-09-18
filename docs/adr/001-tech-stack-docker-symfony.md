# 001: Tech stack — Docker + Symfony

| Field              | Value                                  |
| ------------------ |----------------------------------------|
| **Created By**     | Martin Yde Granath                     |
| **Date**           | 2026-06-08                             |
| **Decision Maker** | ITK Dev team                           |
| **Stakeholders**   | ITK Dev developers, future maintainers |
| **Status**         | Accepted                               |

## Context

ai-reolen is a new application that needs a runtime, a web framework, and a
reproducible local development environment. The project is built and
maintained inside the ITK Dev team, which already operates a fleet of
PHP services and has an established convention for Docker-based local
development.

We need to pick the foundation now — before any application code is
written — so that subsequent decisions (database, queue, deployment
pipeline) can build on it.

### Drivers

- **Functional:**
  - Serve HTTP requests, render templates, and expose a console for
    background commands.
  - Provide a relational data store, mail capture in development, and
    HTTPS routing that mirrors production.
- **Non-functional:**
  - **Team familiarity** — minimise onboarding cost for ITK Dev
    developers who already work with PHP and Symfony.
  - **Reproducibility** — every developer (and CI) should run the same
    services with the same configuration.
  - **Long-term support** — the chosen stack should still be vendor-
    supported well past the project's expected lifetime.
  - **Operational alignment** — the local setup should resemble how the
    service runs on ITK Dev hosting (Traefik fronting nginx + phpfpm).

### Options Considered

1. **PHP / Symfony 8 on the ITK Dev Docker template (`symfony-8`).**
   - Pros: matches existing ITK Dev projects; ships with phpfpm, nginx,
     MariaDB, Mailpit and Traefik routing out of the box; Symfony 8 is
     the current LTS line; ergonomic tooling (`bin/console`, Twig,
     Doctrine, Messenger) is already familiar to the team.
   - Cons: PHP imposes some constraints on long-running workloads;
     coupling to the ITK Dev template means we inherit its release
     cadence.
2. **PHP / Laravel on a custom Docker setup.**
   - Pros: similar runtime, similar ecosystem.
   - Cons: diverges from every other ITK Dev service; we would
     re-implement parts of the `symfony-8` template (Traefik, mail,
     CI workflows) by hand; higher long-term maintenance cost.
3. **Node.js / NestJS on a bespoke Docker setup.**
   - Pros: single language across backend and any future frontend
     tooling; strong async I/O story.
   - Cons: no internal expertise; would not benefit from the ITK Dev
     Docker template or shared CI workflows; higher onboarding and
     operations cost.
4. **Python / FastAPI on a bespoke Docker setup.**
   - Pros: convenient for AI/ML adjacent code paths.
   - Cons: same divergence problem as option 3; the AI integration
     surface of ai-reolen does not require running models locally, so the
     Python ecosystem advantage does not pay off here.

## Decision

Adopt **PHP 8.4 + Symfony 8** running on the **ITK Dev Docker
`symfony-8` template** (phpfpm + nginx + MariaDB + Mailpit, fronted by
Traefik) as the application's foundation.

Rationale:

- The team already maintains Symfony services with this exact stack, so
  developer onboarding cost is effectively zero.
- The `symfony-8` template encodes the team's local-development and CI
  conventions; using it keeps ai-ai-reolen consistent with the rest of the
  ITK Dev portfolio and lets us inherit improvements over time.
- Symfony 8 is an LTS release with a multi-year support window, which
  fits the expected lifetime of this project.
- Production hosting at ITK Dev already runs Traefik in front of
  nginx + phpfpm, so the local setup matches production closely enough
  to catch most environment-specific issues before deployment.

## Consequences

### Positive

- Consistent developer experience across ITK Dev projects.
- Local environment mirrors production (Traefik + nginx + phpfpm + MariaDB).
- CI workflows from the template (coding standards, composer
  normalization, markdown linting) work out of the box.
- Mail is captured locally via Mailpit, avoiding accidental sends.

### Negative / Trade-offs

- We follow the `symfony-8` template's choices (database engine, PHP
  version, service layout). Deviating from it later has a real cost.
- Background processing that would benefit from a long-lived runtime
  must be solved within PHP's model (workers via Symfony Messenger,
  Supervisor, or similar) instead of natively.
