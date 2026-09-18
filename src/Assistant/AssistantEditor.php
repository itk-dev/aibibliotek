<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Assistant\Format\FormatAdapterRegistry;
use App\Assistant\Model\ModelMap;
use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\Tag;
use App\Enum\DataSensitivity;
use App\Repository\OrganizationRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Owns the "update an existing assistant from form data" flow.
 *
 * Mirrors {@see AssistantCreator}'s branches so the edit wizard —
 * which shares its shell with the create wizard — persists changes
 * with exactly the same normalisation rules: language-model aliases
 * are folded to their canonical id, blank strings on the nullable
 * columns become `null`, and unknown / malformed organisation ULIDs
 * resolve to `null` instead of raising so a tampered POST doesn't
 * 500. The controller stays thin: parse the request, call
 * {@see self::update()}, render the receipt.
 */
final class AssistantEditor
{
    /**
     * @param FormatAdapterRegistry  $formats       resolves the adapter that validates and parses the (possibly-replaced) config
     * @param EntityManagerInterface $entityManager Doctrine entity manager whose flush persists the mutation
     * @param TagRepository          $tags          resolves tag names to shared Tag entities
     * @param ModelMap               $modelMap      folds alias/legacy model ids to their canonical form on persist
     * @param OrganizationRepository $organizations resolves the organization the assistant is being shared on behalf of
     */
    public function __construct(
        private readonly FormatAdapterRegistry $formats,
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tags,
        private readonly ModelMap $modelMap,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    /**
     * Apply the wizard's edited fields to `$assistant` and flush.
     *
     * The raw config is re-parsed through the current framework's
     * adapter, so replacing the pasted JSON on step 1 of the edit
     * wizard swaps the stored `sourceConfig` in place. Everything
     * else — title, description, model, tags, organization,
     * tagline, knowledge description, data sensitivity — is written
     * onto the entity via its setters so Doctrine's change tracker
     * emits an UPDATE with only the columns that actually moved.
     *
     * @param Assistant            $assistant            the persisted row to mutate in place
     * @param string               $title                title of the assistant
     * @param string               $description          long-form description
     * @param string               $languageModel        model identifier snapshot (e.g. `gpt-4o`)
     * @param string               $framework            format/framework id; selects the adapter (e.g. `openwebui`)
     * @param list<string>         $tags                 zero or more catalogue tags
     * @param string               $rawConfig            raw uploaded config; validated then parsed by the adapter
     * @param string|null          $organizationId       ULID (string) of the sharing organization, `null` when unset
     * @param string|null          $tagline              short one-line tagline shown in list views
     * @param string|null          $knowledgeDescription free-form description of the knowledge base the assistant relies on
     * @param DataSensitivity|null $dataSensitivity      classification for the assistant's data
     *
     * @return Assistant the same instance, mutated and flushed
     *
     * @throws InvalidAssistantInputException when the framework has no adapter or the config fails validation
     */
    public function update(
        Assistant $assistant,
        string $title,
        string $description,
        string $languageModel,
        string $framework,
        array $tags,
        string $rawConfig,
        ?string $organizationId = null,
        ?string $tagline = null,
        ?string $knowledgeDescription = null,
        ?DataSensitivity $dataSensitivity = null,
    ): Assistant {
        // Same guard-then-validate ordering as AssistantCreator so an
        // unknown format id surfaces as the same form-rendered
        // exception rather than a 500 from the registry.
        if (!$this->formats->has($framework)) {
            throw new InvalidAssistantInputException([\sprintf('Unknown format "%s".', $framework)]);
        }

        $source = $this->formats->get($framework)->parseToSource($rawConfig);
        $canonicalModel = $this->modelMap->normalise($languageModel) ?? $languageModel;

        $assistant
            ->setTitle($title)
            ->setDescription($description)
            ->setLanguageModel($canonicalModel)
            ->setFramework($framework)
            ->setOrganization($this->resolveOrganization($organizationId))
            ->setTagline($this->emptyToNull($tagline))
            ->setKnowledgeDescription($this->emptyToNull($knowledgeDescription))
            ->setDataSensitivity($dataSensitivity)
            ->setSourceConfig($source)
        ;

        $this->syncTags($assistant, $tags);

        $this->entityManager->flush();

        return $assistant;
    }

    /**
     * Resolve the `$organizationId` ULID (as a string) to an
     * {@see Organization} entity, or `null` for `null` / blank /
     * unknown ids.
     *
     * @param string|null $organizationId a ULID string or `null`
     *
     * @return Organization|null the resolved organization, or `null`
     *                           when the id is blank, malformed, or
     *                           not found
     */
    private function resolveOrganization(?string $organizationId): ?Organization
    {
        if (null === $organizationId || '' === $organizationId) {
            return null;
        }

        if (!Ulid::isValid($organizationId)) {
            return null;
        }

        return $this->organizations->find(Ulid::fromString($organizationId));
    }

    /**
     * Fold an empty or whitespace-only string to `null` for the
     * nullable columns.
     *
     * @param string|null $value the raw form value
     *
     * @return string|null the trimmed value, or `null` when empty
     */
    private function emptyToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Reconcile the assistant's tag collection with the submitted list.
     *
     * Removes tags no longer selected, adds tags that appeared for the
     * first time, and reuses existing {@see Tag} entities by name so
     * the catalogue's tag facet stays deduplicated. Ordering is not
     * significant to the join table, so the reconciliation is set-based.
     *
     * @param Assistant    $assistant the entity whose tag collection is being reconciled
     * @param list<string> $names     submitted tag names, already trimmed and non-empty
     */
    private function syncTags(Assistant $assistant, array $names): array
    {
        $desired = [];
        foreach ($names as $name) {
            $desired[$name] ??= $this->tags->findOneByName($name) ?? new Tag($name);
        }

        // Detach tags no longer on the submitted list.
        foreach ($assistant->getTags() as $existing) {
            if (!\in_array($existing, $desired, true)) {
                $assistant->removeTag($existing);
            }
        }

        // Attach tags that appeared for the first time. `addTag` is
        // idempotent, so tags already on the collection stay put.
        foreach ($desired as $tag) {
            $assistant->addTag($tag);
        }

        return array_values($desired);
    }
}
