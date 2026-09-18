<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Model\ModelMap;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Pre-fills step 2 of the create wizard from the config pasted on
 * step 1, in whatever format it was detected as.
 *
 * Detects the source format, records it on the draft, and copies the
 * canonical model's fields into the still-empty review fields. The
 * copy is deliberately forgiving: only empty destination fields are
 * touched, so a Back → edit → Next round-trip never clobbers a value
 * the user already changed. An unrecognised payload leaves the draft
 * untouched — the step-1 constraint reports the error separately.
 *
 * The detected base model is folded onto its canonical id via
 * {@see ModelMap} so the step-2 model selector defaults to a recognised
 * choice; an unrecognised model is kept verbatim as a custom value.
 */
final class AssistantDraftPrefiller
{
    /**
     * @param FormatAdapterRegistry  $formats       detects the format and converts it to the canonical model
     * @param ModelMap               $modelMap      folds the detected base model onto its canonical id
     * @param OrganizationRepository $organizations looked up when defaulting the draft's organization from the acting user's e-mail domain
     * @param Security               $security      resolves the currently-authenticated user for the organization default
     */
    public function __construct(
        private readonly FormatAdapterRegistry $formats,
        private readonly ModelMap $modelMap,
        private readonly OrganizationRepository $organizations,
        private readonly Security $security,
    ) {
    }

    /**
     * Detect `$draft->sourceConfig`'s format and pre-fill the draft.
     *
     * Two branches, keyed off `$draft->editingAssistantId`:
     *
     * - Create path (`editingAssistantId === null`): sets the
     *   detected framework id, then refreshes the JSON-derived
     *   fields (`title`, `description`, `languageModel`, `tags`)
     *   against the new canonical values only when the field still
     *   matches the previously-recorded {@see AssistantDraft::$jsonBaseline}
     *   — i.e., the curator hasn't touched it since the last
     *   prefill. Manual edits are preserved. The organisation is
     *   defaulted from the acting user's e-mail domain when unset.
     *
     * - Edit path (`editingAssistantId !== null`): a no-op when the
     *   raw source config still matches
     *   {@see AssistantDraft::$initialSourceConfig} — the curator
     *   hasn't touched the JSON, so the entity-hydrated metadata
     *   stays. Otherwise the curator has pasted a different config,
     *   and the derived fields are refreshed unconditionally to
     *   reflect it; `initialSourceConfig` is then rolled forward so
     *   a further re-upload triggers the same treatment. No
     *   organisation defaulting on edit — the persisted assistant's
     *   organisation stays authoritative.
     *
     * Payloads no adapter recognises are a no-op on both paths —
     * the step-1 constraint reports the error separately.
     *
     * @param AssistantDraft $draft the DTO to mutate in place
     */
    public function prefill(AssistantDraft $draft): void
    {
        $adapter = $this->formats->detect($draft->sourceConfig);
        if (null === $adapter) {
            return;
        }

        if (null !== $draft->editingAssistantId) {
            $this->prefillForEdit($draft, $adapter);

            return;
        }

        $this->prefillForCreate($draft, $adapter);
    }

    /**
     * Refresh derived fields when the curator swaps the raw JSON on
     * the edit wizard's step 1.
     *
     * A no-op when the current source config still matches the one
     * the entity was hydrated with; the curator is simply
     * round-tripping through step 1 and their step-2 edits must not
     * be clobbered. When it differs, every derived field is
     * refreshed from the new canonical values — the curator
     * explicitly uploaded a different config and step 2 must
     * reflect it.
     *
     * @param AssistantDraft       $draft   the DTO to mutate in place
     * @param Format\FormatAdapter $adapter the format adapter matched to the current source config
     */
    private function prefillForEdit(AssistantDraft $draft, Format\FormatAdapter $adapter): void
    {
        if ($draft->sourceConfig === $draft->initialSourceConfig) {
            return;
        }

        $draft->framework = $adapter->id();
        $canonical = $adapter->sourceToCanonical($adapter->parseToSource($draft->sourceConfig));

        // The new JSON is the source of truth. For the three
        // form-required fields (title / description / language
        // model), only overwrite when the canonical provides a
        // non-empty value — an incomplete config would otherwise
        // leave the metadata step failing NotBlank validation on
        // fields the entity already carried valid values for.
        if ('' !== $canonical->name) {
            $draft->title = $canonical->name;
        }

        $canonicalDescription = $canonical->description ?? $canonical->systemPrompt ?? '';
        if ('' !== $canonicalDescription) {
            $draft->description = $canonicalDescription;
        }

        if (null !== $canonical->baseModel && '' !== $canonical->baseModel) {
            $draft->languageModel = $this->modelMap->normalise($canonical->baseModel) ?? $canonical->baseModel;
        }

        // Tags is the exception — it carries no form-level
        // required constraint, and a curator who pastes a config
        // with no tags means "clear the tags", not "keep what was
        // already there".
        $draft->tags = $canonical->tags;

        // Roll the "initial" pointer forward so a subsequent
        // re-upload of yet another JSON is recognised as a fresh
        // change and gets the same refresh treatment.
        $draft->initialSourceConfig = $draft->sourceConfig;
    }

    /**
     * Baseline-compare refresh for the create wizard.
     *
     * @param AssistantDraft       $draft   the DTO to mutate in place
     * @param Format\FormatAdapter $adapter the format adapter matched to the current source config
     */
    private function prefillForCreate(AssistantDraft $draft, Format\FormatAdapter $adapter): void
    {
        $draft->framework = $adapter->id();
        $canonical = $adapter->sourceToCanonical($adapter->parseToSource($draft->sourceConfig));

        $canonicalDescription = $canonical->description ?? $canonical->systemPrompt ?? '';
        $canonicalLanguageModel = null !== $canonical->baseModel && '' !== $canonical->baseModel
            ? ($this->modelMap->normalise($canonical->baseModel) ?? $canonical->baseModel)
            : '';

        $oldBaseline = $draft->jsonBaseline;

        // "Unchanged since last prefill" is the signal to overwrite —
        // the curator hasn't manually edited the field, so refreshing
        // from a re-uploaded JSON is safe. When the field differs from
        // the recorded baseline the curator has touched it and we
        // preserve their edit. The empty-string guard on the new
        // canonical value prevents an incomplete second upload from
        // wiping a field the first upload correctly populated.
        if ($draft->title === ($oldBaseline['title'] ?? '') && '' !== $canonical->name) {
            $draft->title = $canonical->name;
        }

        if ($draft->description === ($oldBaseline['description'] ?? '') && '' !== $canonicalDescription) {
            $draft->description = $canonicalDescription;
        }

        if ($draft->languageModel === ($oldBaseline['languageModel'] ?? '') && '' !== $canonicalLanguageModel) {
            $draft->languageModel = $canonicalLanguageModel;
        }

        if ($draft->tags === ($oldBaseline['tags'] ?? []) && [] !== $canonical->tags) {
            $draft->tags = $canonical->tags;
        }

        // Record the new baseline last so the comparisons above see the
        // OLD one — the metadata step then flags any post-prefill edits
        // against the fresh JSON via the same jsonBaseline entries.
        $draft->jsonBaseline = [
            'title' => $canonical->name,
            'description' => $canonicalDescription,
            'languageModel' => $canonicalLanguageModel,
            'tags' => $canonical->tags,
        ];

        if (null === $draft->organizationId) {
            $organization = $this->resolveOrganizationFromActingUser();
            if (null !== $organization) {
                $draft->organizationId = (string) $organization->getId();
            }
        }
    }

    /**
     * Look up an organisation to default the draft's owner from.
     *
     * The acting user's e-mail domain — the substring after the last
     * `@` — is fed to
     * {@see OrganizationRepository::findOneByEmailDomain()}. Anonymous
     * requests, users with a missing/malformed e-mail, or a domain no
     * organisation claims all resolve to `null` so the curator sees an
     * empty picker and can still submit.
     *
     * @return \App\Entity\Organization|null the matching organisation,
     *                                       or `null` when the acting
     *                                       user has no e-mail domain
     *                                       or no organisation claims it
     */
    private function resolveOrganizationFromActingUser(): ?\App\Entity\Organization
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $email = (string) $user->getEmail();
        $atPosition = strrpos($email, '@');
        if (false === $atPosition) {
            return null;
        }

        $domain = substr($email, $atPosition + 1);

        return $this->organizations->findOneByEmailDomain($domain);
    }
}
