# 002: Frontend tooling — Tailwind + AssetMapper + Stimulus

| Field              | Value                                  |
| ------------------ |----------------------------------------|
| **Created By**     | Martin Yde Granath                     |
| **Date**           | 2026-06-08                             |
| **Decision Maker** | ITK Dev team                           |
| **Stakeholders**   | ITK Dev developers, future maintainers |
| **Status**         | Accepted                               |

## Context

ai-reolen is a Symfony 8 application with no real frontend stack yet.
`assets/app.css` and `assets/app.js` are placeholders, there is no
`package.json`, and no asset bundler is configured. Before UI work
starts in earnest (frontpage, catalogue listing, login, assistant
details, etc.), the team needs to commit to a CSS framework, an asset
pipeline, and a JavaScript layer so subsequent UI issues have stable
ground to build on.

This ADR records that decision and supersedes the deferral noted on
[#38](https://github.com/itk-dev/ai-reolen/issues/38).

### Drivers

- **Functional:**
  - Style server-rendered Twig templates consistently across pages.
  - Ship small, behaviour-only JavaScript (form helpers, toggles,
    auto-complete) without taking on a SPA framework.
  - Provide a build pipeline that produces fingerprinted, cacheable
    assets in production.
- **Non-functional:**
  - **Symfony alignment** — lean on the tooling that the Symfony team
    ships and documents by default, so onboarding is cheap and the
    stack ages well alongside the framework.
  - **No Node toolchain in CI/production** — the project's CI workflows
    and ITK Dev hosting expect a PHP-only runtime. Anything that pulls
    Node into the deploy path adds operational surface area.
  - **Team familiarity** — the team already writes Symfony apps with
    Twig and small JavaScript sprinkles. The chosen stack should match
    that working style.
  - **Reversibility** — if a future feature genuinely needs a heavier
    frontend (Vue / React island, rich client app), we should be able
    to add it alongside without rewriting the rest.

### Options Considered

#### CSS framework

1. **Tailwind CSS** — utility-first, no opinionated components.
   - Pros: scales cleanly from small apps to large; integrates with
     Symfony via the `symfonycasts/tailwind-bundle`, which downloads a
     standalone Tailwind binary and removes the need for Node in the
     build; design stays in templates instead of in a separate CSS
     file.
   - Cons: needs a Tailwind build step (PHP-driven, but still a step);
     verbose class lists in templates.
2. **Bootstrap 5** — component-first.
   - Pros: fastest path to a usable UI; familiar to most developers.
   - Cons: opinionated look that is hard to escape without overriding
     a lot; heavier CSS output; tends to push teams into Bootstrap-
     shaped designs.
3. **Plain CSS / custom.**
   - Pros: maximum flexibility, no framework lock-in.
   - Cons: high upfront and ongoing cost; reinvents utilities the team
     would otherwise inherit.
4. **Other utility frameworks (Pico, Open Props, Bulma, etc.).**
   - Niche compared to Tailwind in the Symfony ecosystem; less
     documentation and bundle support.

#### Asset pipeline

1. **Symfony AssetMapper** — built-in to Symfony 8, no Node build step,
   importmaps.
   - Pros: ships with Symfony; no `package.json`, no `node_modules`,
     no separate Node container; native ES modules via importmaps;
     pairs cleanly with the Tailwind bundle and Stimulus bundle.
   - Cons: not suited to projects that need heavy JS toolchains
     (Webpack-style code splitting, TypeScript compilation, JSX).
2. **Webpack Encore** — Symfony's classic Webpack wrapper.
   - Pros: mature; handles Sass / PostCSS / Babel transparently.
   - Cons: requires Node in the build pipeline and in CI; another
     container or build stage to maintain; growing friction as Symfony
     itself moves toward AssetMapper.
3. **Vite** — modern bundler with HMR.
   - Pros: fast dev loop; ESM-native.
   - Cons: requires Node and a community Symfony bridge; least
     conventional choice in a Symfony shop.

#### JavaScript layer

1. **Vanilla JS via Stimulus (Symfony UX).**
   - Pros: server-rendered HTML stays the source of truth; behaviour
     attaches via `data-controller` attributes; Symfony UX components
     drop in without adopting a SPA framework; aligns with how the
     team already builds Symfony apps.
   - Cons: ceiling exists — genuinely rich client UI is awkward in
     pure Stimulus.
2. **Vue or React island(s).**
   - Pros: better for rich, stateful UI.
   - Cons: not justified by current scope; adds bundling and SSR
     considerations; can be introduced later as an island if needed.

## Decision

Adopt the following frontend stack:

- **CSS framework:** Tailwind CSS, integrated via the
  [`symfonycasts/tailwind-bundle`](https://github.com/SymfonyCasts/tailwind-bundle).
- **Asset pipeline:** Symfony AssetMapper.
- **JavaScript layer:** Vanilla JavaScript via
  [Stimulus](https://stimulus.hotwired.dev/) using
  [`symfony/stimulus-bundle`](https://github.com/symfony/stimulus-bundle).

This is the combination that yepzdk recommended in the discussion on
issue #38, on the grounds that it leans on the defaults Symfony
provides.

Rationale:

- Tailwind keeps styling in the template, scales from small apps to
  large, and — via the Symfony Casts bundle — runs without a Node
  toolchain.
- AssetMapper is Symfony's first-party asset pipeline. No `package.json`,
  no `node_modules`, no Node container, no separate build stage in CI.
  Production deploy is still a `composer install` and a console
  command.
- Stimulus is the JavaScript layer Symfony UX is built around. It
  matches the "server-rendered HTML with behaviour sprinkles" model the
  team already uses and leaves the door open to add Symfony UX
  components (Live Components, Turbo, Autocomplete) as they become
  useful.

## Consequences

### Positive

- The whole frontend toolchain runs inside the existing phpfpm
  container. No new Docker services, no Node in CI, no `package.json`.
- The decision aligns with Symfony 8's recommended path, so framework
  upgrades will keep working out of the box.
- UI work in follow-up issues (#3 / #40 frontpage, #2 login, #15
  catalogue listing, #20 assistant details, and the wider catalogue/
  search set) can start immediately on a known stack.
- Future Symfony UX components (Live Components, Turbo, Autocomplete)
  can be added incrementally without re-platforming.

### Negative / Trade-offs

- We commit to Tailwind's utility-first model; pages will carry long
  class lists in markup. Teams that prefer separate component CSS will
  need to adapt.
- AssetMapper is a poor fit if we ever need TypeScript, JSX, or rich
  bundling. If that need appears, we can introduce a Vite-built island
  alongside AssetMapper rather than replacing it wholesale.
- Stimulus has a learning curve for developers new to it, though it is
  well documented and stays close to plain DOM APIs.
