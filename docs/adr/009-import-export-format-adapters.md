# 009: Import/export format adapters and cross-system model mapping

| Field | Value |
| ----- | ----- |
| **Created By** | Troels Ugilt Jensen |
| **Date** | 2026-07-02 |
| **Decision Maker** | Troels Ugilt Jensen |
| **Stakeholders** | ai-reolen maintainers |
| **Status** | Draft |

## Context

The catalogue was built around a single interchange format (OpenWebUI).
The `FormatAdapter` abstraction reduces any format to a neutral
`CanonicalModel`, but only one adapter existed. Users frequently import an
assistant whose source format is *sparse* relative to other tools (a bare
Ollama Modelfile carries only `FROM`/`SYSTEM`; a LibreChat preset has no
tags or description) yet want to run that assistant in a *different*
system. Making that work requires more than more adapters: the export must
re-import cleanly elsewhere even when the source didn't carry everything
the target needs, and a base-model name valid in one system (`llama3.2`)
is meaningless in another (OpenAI).

### Drivers

- **Functional:** export/import across the tools Danish public-sector
  teams actually run; an incomplete import must still produce a
  re-importable export elsewhere.
- **Non-functional:** deterministic format detection across several JSON
  formats; no silent data corruption (a swapped model changes behaviour);
  additive design so new formats need no wizard/controller changes; the
  100% coverage gate.

### Options Considered

1. **Only add adapters.** Cheapest, but a cross-format export of a sparse
   source produces payloads that won't re-import, and base models don't
   translate — so "export to another system" fails in practice.
2. **Auto-substitute a nearest model when none matches.** Makes every
   export structurally importable, but silently changes behaviour and
   misrepresents what the curator built.
3. **Adapters + creation-time completeness + name-only model mapping +
   export validation (chosen).** Prompt for missing fields when the
   assistant is created, translate model *names* deterministically, pass
   through (and warn) when a model has no equivalent, and validate the
   rendered payload before download.

## Decision

**Format set.** OpenWebUI (incumbent) plus four new `FormatAdapter`s:
AI-reolen **native** JSON (a lossless envelope over the canonical fields),
**Ollama Modelfile** (a text DSL), **LibreChat** preset, and **OpenAI
Assistants**. Each self-registers via the `app.format_adapter` tag. The
shared JSON syntax + schema pipeline lives in a `JsonSchemaConfigValidator`
base with one schema per format under `config/schema/`.

**Experimental status.** OpenWebUI is the only manually-verified format;
the four new ones are best-effort. Each adapter declares `isExperimental()`
so the wizard and detail page can caution users before they rely on an
unverified round-trip, and a format can graduate to stable by flipping its
own flag. The share wizard is otherwise format-agnostic (no OpenWebUI/JSON
wording; the file picker accepts `.modelfile` as well as `.json`).

**Detection priority.** With four JSON formats, `FormatAdapterRegistry::detect()`
must be deterministic. Each adapter declares an explicit
`#[AsTaggedItem(priority)]`, ordering detection most-discriminating-first:
native → openai → librechat → openwebui → ollama. OpenWebUI's schema is
permissive, so it sits below the sharply-discriminated formats; Ollama is
text and never collides with JSON.

**Cross-format completeness.** `FormatAdapter::requiredCanonicalFields()`
and `FormatAdapterRegistry::requiredForAnyExport()` express which canonical
fields a format needs; the create wizard prompts for them with sensible
defaults. Adapters synthesise structurally-required scaffolding on export,
and `AssistantExporter` validates the rendered payload against the target's
own rules before returning it, so a broken export is never emitted.

**Semantic model mapping.** `config/model_map.yaml` and the `ModelMap`
service fold a source model onto a neutral canonical id and render it to
each target's own id. A model with **no equivalent** in a target is passed
through unchanged and flagged with a **non-blocking warning** (shown on the
detail page and in an `X-Export-Warning` header) — never silently swapped
for a behaviourally different model. Consequently `Assistant.languageModel`
now stores the **canonical** model id rather than the raw source string,
refining the snapshot described in ADR 005.

## Consequences

### Positive

- An assistant imported from any supported tool can be exported to any
  other and re-imported there; the native format is a lossless pivot.
- Detection is deterministic and adding a format stays "implement the
  interface, set a priority" — no wizard/controller/export changes.
- Model translation is honest: names map where an equivalent exists, and
  the absence of one is surfaced rather than hidden.

### Negative / Trade-offs

- `config/model_map.yaml` is curated by hand and must be maintained as
  models come and go; unknown models fall back to pass-through.
- Storing the canonical model id in `languageModel` diverges from the raw
  ADR 005 snapshot, so historical rows carrying a raw name rely on the
  pass-through fallback on export.
- Exporting *into* OpenWebUI still drops a cross-format system prompt
  (OpenWebUI's `canonicalToSource` preserves only its own stored source);
  the new adapters carry the prompt, but OpenWebUI-as-target does not yet.
