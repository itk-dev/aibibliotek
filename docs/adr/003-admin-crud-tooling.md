# 003: Admin / CRUD tooling — Symfony Form + hand-written controllers

| Field              | Value                                  |
| ------------------ |----------------------------------------|
| **Created By**     | Martin Yde Granath                     |
| **Date**           | 2026-06-11                             |
| **Decision Maker** | ITK Dev team                           |
| **Stakeholders**   | ITK Dev developers, future maintainers |
| **Status**         | Draft                                  |

## Context

ai-reolen needs backend management screens for at least:

- User management ([#12](https://github.com/itk-dev/ai-reolen/issues/12),
  [#13](https://github.com/itk-dev/ai-reolen/issues/13)), where a domain
  manager controls users within their own domain — i.e. row-level
  scoping that is not a simple CRUD over the User entity.
- Assistant management
  ([#14](https://github.com/itk-dev/ai-reolen/issues/14),
  [#20](https://github.com/itk-dev/ai-reolen/issues/20)). The assistant
  is the product's core entity; managing one needs a proper, end-user
  facing frontend rather than a generic admin grid.
- Smaller administrative surfaces: tag/category management
  ([#16](https://github.com/itk-dev/ai-reolen/issues/16)) and submission
  moderation ([#25](https://github.com/itk-dev/ai-reolen/issues/25)).

Before any of these are built we need to choose how admin screens are
produced so the same approach is used throughout the codebase.

This ADR is scoped to the **admin / CRUD generator** choice. Form
component conventions (location of form types, validation strategy,
form theme) and the in-admin authorisation model are intentionally
left to follow-on decisions once the data model and role model are
clearer.

### Drivers

- **Functional:**
  - Support row-level authorisation (domain-manager scope), not just
    whole-entity gates.
  - Allow the assistant's management screens to be hand-crafted as
    first-class product UI, not a generic admin view.
  - Keep the small administrative surfaces (tags, moderation queue)
    cheap to implement.
- **Non-functional:**
  - Minimise added complexity and dependencies during early
    development.
  - Stay aligned with the rest of the stack — Symfony Form is already
    available via `symfony/framework-bundle`.
  - Keep the door open for adopting a generator later if scope grows.

### Options Considered

1. **Custom CRUD with Symfony Form + hand-written controllers and
   Twig templates.**
   - Pros: no extra dependency; full control over routing, templates,
     and authorisation; aligns naturally with row-level scoping; small
     forms are tractable to hand-write.
   - Cons: more code per screen; no scaffolded list / filter / sort
     UI; the team must agree on a few conventions (form type location,
     template structure) to avoid drift.
2. **EasyAdmin (`EasyCorp/EasyAdminBundle`).**
   - Pros: fastest path to a working admin from Doctrine entities;
     built-in filters, search, sort, batch actions; PHP-only
     configuration; integrates with Symfony Security.
   - Cons: opinionated UI shipped with Bootstrap markup that conflicts
     visually with the Tailwind frontend from ADR 002; the "all admin
     in one dashboard controller" pattern grows into a god-controller
     without careful splitting; row-level scoping (domain manager)
     requires custom data providers and voters that work around the
     bundle's defaults; non-Doctrine data sources are awkward.
3. **Sonata Admin.**
   - Pros: feature-rich.
   - Cons: heavier setup; larger surface area; same UI-alignment
     concerns as EasyAdmin; overkill for the few admin screens this
     project actually needs.
4. **API Platform Admin (React-Admin).**
   - Pros: natural choice if the project goes API-first with a
     separate React frontend.
   - Cons: presupposes an API-first architecture ai-reolen has not
     committed to; introduces a second runtime and build toolchain
     just for the admin UI.

## Decision

Adopt **option 1 — custom CRUD with Symfony Form + hand-written
controllers and Twig templates** for all backend management screens.

Rationale:

- The backend surface is small. There are essentially three areas
  (users, assistants, light moderation), and the assistant screens
  are first-class product UI that any admin generator would have to
  step aside for anyway.
- User management needs row-level scoping (a domain manager only sees
  and edits their own domain's users). Implementing that on top of a
  generic CRUD generator means writing custom data providers and
  voters that override the generator's defaults — at which point the
  generator is adding complexity without saving code.
- EasyAdmin's Bootstrap-themed UI would diverge from the Tailwind
  frontend chosen in ADR 002. Aligning them is possible but is itself
  a recurring cost.
- Symfony Form is already part of the framework and is well understood
  by the team. Hand-writing the few remaining administrative forms is
  tractable and easy to review.
- Keeping the dependency surface small now preserves the freedom to
  adopt a generator later if the admin surface grows in ways we did
  not predict.

## Consequences

### Positive

- No additional bundle to track, configure, or upgrade.
- Each management screen owns its routing, template, and security
  decisions, which makes row-level authorisation patterns natural to
  express.
- The admin UI shares the same Tailwind component library as the
  public site, so look and feel stay consistent without extra theming.
- Future contributors only need familiarity with Symfony's core
  (Controller, Form, Twig, Security) — no bundle-specific patterns to
  learn.

### Negative / Trade-offs

- More boilerplate per screen than a generator would produce. We
  accept this because the number of screens is small.
- No built-in list / filter / sort UI primitives — the project will
  need to evolve its own conventions for those once a second listing
  screen lands.
- If the backend surface grows substantially later, the team may
  re-open this decision and adopt a generator at that point; this ADR
  would then be superseded.

---

_Form component conventions and the in-admin authorisation model are
out of scope here and remain open. They will be settled in follow-on
decisions once the data model
([#14](https://github.com/itk-dev/ai-reolen/issues/14)) and roles
([#12](https://github.com/itk-dev/ai-reolen/issues/12)) firm up._
