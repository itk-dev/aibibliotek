<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Enum\DataSensitivity;

/**
 * In-flight state carried across the three steps of the
 * "share an assistant" wizard.
 *
 * The DTO belongs to the `AssistantCreateFlowType` form flow.
 * Symfony's `SessionDataStorage` serialises it between requests
 * so a user paging back and forth keeps their JSON, extracted
 * metadata, and eventual review edits.
 *
 * The `step` property is the current cursor name, kept in sync
 * by Symfony's `PropertyPathStepAccessor` (wired via the flow's
 * `step_property_path` option). The three steps are `json`,
 * `metadata`, `receipt`. `createdAssistantId` is written by the
 * controller when the flow transitions past step 2 (metadata
 * → receipt), so step 3 can render a permalink and the
 * transition itself stays idempotent (a refresh mid-step-3
 * doesn't re-persist).
 */
final class AssistantDraft
{
    /**
     * Current step name — driven by Symfony's flow cursor via
     * the `step_property_path` option on the flow type.
     */
    public string $step = 'json';

    /**
     * Raw assistant config as the user pasted / uploaded it, in the
     * detected format. The adapter + `AssistantCreator::create()`
     * validate and parse it to the stored source dict at persist time.
     */
    public string $sourceConfig = '';

    public string $title = '';

    public string $description = '';

    public string $framework = 'openwebui';

    public string $languageModel = '';

    /**
     * @var list<string> zero or more tag names as typed on step 2
     */
    public array $tags = [];

    /**
     * ULID (as a string) of the {@see \App\Entity\Organization} the
     * assistant is being shared on behalf of. The wizard's metadata
     * step defaults this from the logged-in user's e-mail domain and
     * lets the curator override it. `null` means "unset" — the
     * detail page renders "ingen tilknyttet organisation" for those.
     */
    public ?string $organizationId = null;

    /**
     * Short one-line tagline shown alongside the title in list views.
     * Empty string means the curator left the field blank on step 2;
     * {@see AssistantCreator::create()} normalises empty strings to
     * `null` on persist so DB queries don't need `LENGTH(...) > 0`
     * guards.
     */
    public string $tagline = '';

    /**
     * Free-form description of the knowledge base / data grounding the
     * assistant. Same empty-string-means-unset convention as
     * {@see self::$tagline}.
     */
    public string $knowledgeDescription = '';

    /**
     * Data-sensitivity classification chosen on step 2. `null` when the
     * curator hasn't picked one yet — the form validates it as required
     * before advancing to step 3.
     */
    public ?DataSensitivity $dataSensitivity = null;

    /**
     * Snapshot of the values {@see AssistantDraftPrefiller} extracted
     * from the uploaded JSON on the step-1 → step-2 transition, keyed
     * by field name (`title`, `description`, `languageModel`, `tags`).
     *
     * Kept alongside the draft's live fields so the metadata step's
     * template can flag which fields the curator has since edited —
     * showing a small "(Ændret)" badge next to the label of any field
     * whose current value no longer matches the JSON baseline. Empty
     * on the edit path (the prefiller uses a different signal —
     * whether the raw source config changed — to decide when to
     * refresh derived fields), so no badges appear there.
     *
     * @var array<string, mixed>
     */
    public array $jsonBaseline = [];

    /**
     * Raw source config the edit wizard hydrated from the persisted
     * entity, kept as a stable reference for
     * {@see AssistantDraftPrefiller::prefill()} to compare against.
     *
     * On the edit path, the prefiller only refreshes derived fields
     * when `$sourceConfig !== $initialSourceConfig` — i.e. the
     * curator has actually pasted / edited a different config than
     * the one the entity was loaded with. Empty on the create path,
     * where the baseline-compare logic on {@see self::$jsonBaseline}
     * governs refresh behaviour instead.
     */
    public string $initialSourceConfig = '';

    /**
     * ULID of the persisted assistant, written by the controller
     * on the metadata→receipt transition. Null until that
     * happens; non-null on step 3 so the template can build a
     * permalink.
     */
    public ?string $createdAssistantId = null;

    /**
     * ULID of the assistant being edited, set by
     * {@see \App\Controller\AssistantEditController} when the
     * wizard is invoked as an edit rather than a create. Presence
     * flips two behaviours:
     *
     * 1. {@see AssistantDraftPrefiller::prefill()} skips its own
     *    metadata / organization / language-model defaults so the
     *    curator's own edits are never clobbered by re-detection.
     * 2. The controller routes the metadata → receipt transition
     *    through {@see AssistantEditor::update()} instead of
     *    {@see AssistantCreator::create()}, updating the existing
     *    row in place.
     */
    public ?string $editingAssistantId = null;
}
