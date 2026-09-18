# 004: Project license — MPL-2.0

| Field              | Value                                                  |
| ------------------ | ------------------------------------------------------ |
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-06-08                                             |
| **Decision Maker** | ITK Dev team (decision by @lilosti)                    |
| **Stakeholders**   | ITK Dev developers, future external contributors,      |
|                    | Danish public-sector projects reusing ai-reolen        |
| **Status**         | Accepted                                               |

## Context

`composer.json` currently declares `"license": "proprietary"` — the
Symfony skeleton default rather than a deliberate choice — and the
repository has no `LICENSE` file at its root. ai-reolen is intended as a
shared catalog for the Danish public sector and may invite external
contributions or be referenced by sister projects, so the licensing
terms need to be explicit before public release or external
contribution.

This ADR records the explicit license decision and the rationale behind
it, so future maintainers and contributors understand what was chosen
and why.

### Drivers

- **Functional:**
  - Allow downstream public-sector projects to reuse and integrate
    ai-reolen without negotiation overhead.
  - Permit ai-reolen code to be embedded in larger works (proprietary or
    differently-licensed) without forcing the entire larger work to
    inherit the license.
- **Non-functional:**
  - **Clarity** — the license must be a recognised SPDX identifier so
    tooling (Composer, Packagist, GitHub, dependency scanners) can
    report it correctly.
  - **Compatibility** — must coexist cleanly with the typical
    open-source dependencies a Symfony project pulls in (MIT, BSD,
    Apache-2.0).
  - **Public-sector fit** — the license should be a defensible choice
    for code produced by a Danish municipality, balancing open reuse
    with a modest "give back modifications" expectation.
  - **Contributor friendliness** — the license should not require a
    Contributor License Agreement or other heavy-weight ceremony.

### Options Considered

1. **MPL-2.0 (Mozilla Public License 2.0).**
   - Pros: weak/file-level copyleft — modifications to MPL-licensed
     files must remain MPL, but the license does not "infect" a larger
     work that merely combines MPL code with other code; SPDX-recognised
     (`MPL-2.0`); explicitly compatible with the Apache-2.0, MIT, and
     BSD licenses common in the Symfony ecosystem; explicitly designed
     to allow combination with GPL/LGPL/AGPL "Secondary Licenses";
     well-understood by legal teams in both public and private sectors.
   - Cons: less universally familiar than MIT/Apache-2.0; the copyleft
     condition, although narrow, does impose obligations on downstream
     users that pure permissive licenses do not.
2. **Permissive (MIT, Apache-2.0, BSD-3-Clause).**
   - Pros: maximises reuse; minimal obligations on downstream users.
   - Cons: no expectation that downstream improvements flow back; for a
     publicly-funded shared catalog we wanted at least a "fixes to our
     files should remain open" baseline.
3. **Strong copyleft (GPL-3.0, AGPL-3.0).**
   - Pros: guarantees downstream derivatives stay open source.
   - Cons: viral / whole-work copyleft is a significant barrier for
     other public-sector projects that want to embed ai-reolen into
     larger systems with mixed licensing; AGPL's network-use clause is
     overkill for a shared library.
4. **EUPL-1.2 (European Union Public Licence).**
   - Pros: explicitly designed for EU public-sector code; recognised on
     Joinup; comes in 23 official EU language versions.
   - Cons: less familiar to international contributors and dependency
     scanners than MPL/MIT/Apache; compatibility with other licenses is
     handled via an appendix-style mechanism that is harder to reason
     about than MPL's per-file model.
5. **Stay proprietary.**
   - Pros: explicit, requires no further action.
   - Cons: defeats the purpose of ai-reolen as a shared public-sector
     catalog; blocks the external-contribution and cross-project-reuse
     scenarios the project was set up to enable.

## Decision

Adopt the **Mozilla Public License 2.0** (SPDX identifier: `MPL-2.0`)
as the project's license.

Rationale:

- **Weak/file-level copyleft suits a public-sector shared catalog.**
  Modifications to MPL-licensed files must remain under the MPL, which
  ensures fixes and improvements to ai-reolen itself flow back to the
  commons. At the same time, the license does not "infect" larger
  works, so other public-sector projects can embed ai-reolen into systems
  with mixed licensing without having to relicense their entire
  codebase.
- **Allows downstream reuse with minimal friction.** Combining MPL code
  with proprietary or differently-licensed code in a Larger Work is
  explicitly permitted, which matches how ai-reolen is expected to be
  consumed by sister projects.
- **SPDX-recognised and tool-friendly.** `MPL-2.0` is in the canonical
  SPDX list, so Composer, Packagist, GitHub's license detection, and
  common dependency-scanning tools understand it out of the box.
- **Compatible with the Symfony ecosystem.** Symfony itself and most of
  its dependencies are MIT/BSD/Apache-2.0 licensed; MPL-2.0 combines
  cleanly with all of them.
- **Explicit Secondary-License compatibility.** MPL-2.0 was designed to
  coexist with GPL-2.0, LGPL-2.1, and AGPL-3.0 (and later versions),
  so it does not lock out downstream consumers who themselves use
  copyleft licenses.
- **Defensible and well-understood.** MPL-2.0 has been in widespread
  use since 2012 (Firefox, Thunderbird, Rust crates, HashiCorp tooling
  prior to BSL, many enterprise libraries), so its terms are familiar
  to legal reviewers in both public and private sectors.

## Consequences

### Positive

- Modifications to ai-reolen files are guaranteed to remain MPL, keeping
  improvements available to the wider public-sector community.
- Downstream projects can embed ai-reolen in larger works under terms of
  their choice, including proprietary terms.
- Recognised by automated tooling (SPDX, GitHub, Composer, dependency
  scanners), so license metadata is trustworthy throughout the
  toolchain.
- Compatible with the MIT/BSD/Apache-2.0 dependencies typical in the
  Symfony ecosystem and with GPL-family Secondary Licenses.
- Removes the ambiguity created by the placeholder `"proprietary"`
  value in `composer.json`.

### Negative / Trade-offs

- The per-file copyleft does impose a (modest) obligation on downstream
  consumers who modify ai-reolens own files — those modified files must
  remain MPL and carry the appropriate notice.
- MPL-2.0 is less universally familiar than MIT or Apache-2.0, which
  may add a small amount of education overhead for first-time external
  contributors.
- Switching license later (relaxing or tightening) requires consent
  from all contributors whose contributions are non-trivial, so this
  choice is effectively durable.
