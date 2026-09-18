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
 * Owns the "create an assistant from form data" flow.
 *
 * Sits between {@see \App\Controller\AssistantCreateController}
 * and Doctrine + the format adapters so the controller stays thin:
 * it parses the request, calls one method here, and renders the
 * response.
 */
final class AssistantCreator
{
    /**
     * @param FormatAdapterRegistry  $formats       resolves the adapter that validates and parses the upload
     * @param EntityManagerInterface $entityManager Doctrine entity manager that persists the Assistant
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
     * Validate the uploaded config and persist a new Assistant.
     *
     * The raw config text is handed to the adapter for `$framework`,
     * which validates it, reduces it to that format's model, and
     * strips instance-specific data and PII. Only the cleaned source
     * dict is stored in the `source_config` Doctrine `JSON` column
     * — the uploading user's details, access grants, timestamps, and
     * knowledge references never reach the database.
     *
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
     * @return Assistant the persisted assistant with its id assigned
     *
     * @throws InvalidAssistantInputException when the framework has no adapter or the config fails validation
     */
    public function create(
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
        // Guard the format id here rather than letting the registry
        // throw a raw InvalidArgumentException: create() is a public
        // service boundary, and a tampered/unknown framework should
        // surface as the form-rendered InvalidAssistantInputException
        // like every other rejected input, not a 500.
        if (!$this->formats->has($framework)) {
            throw new InvalidAssistantInputException([\sprintf('Unknown format "%s".', $framework)]);
        }

        $source = $this->formats->get($framework)->parseToSource($rawConfig);

        // Fold aliases and legacy spellings to the canonical id defined
        // in config/model_map.yaml, so the catalogue's language-model
        // facet stays deduplicated no matter which spelling a curator
        // typed. Unknown/free-typed values pass through untouched.
        $canonicalModel = $this->modelMap->normalise($languageModel) ?? $languageModel;

        $assistant = new Assistant(
            title: $title,
            description: $description,
            languageModel: $canonicalModel,
            framework: $framework,
            tags: $this->resolveTags($tags),
            organization: $this->resolveOrganization($organizationId),
            tagline: $this->emptyToNull($tagline),
            knowledgeDescription: $this->emptyToNull($knowledgeDescription),
            dataSensitivity: $dataSensitivity,
        );
        $assistant->setSourceConfig($source);

        $this->entityManager->persist($assistant);
        $this->entityManager->flush();

        return $assistant;
    }

    /**
     * Resolve the `$organizationId` ULID (as a string) to an
     * {@see Organization} entity, or `null` for `null` / blank / unknown
     * ids.
     *
     * A malformed ULID string comes back as `null` rather than a raised
     * exception — the wizard's form field only ever writes back valid
     * choices, but a hand-crafted POST that tampered with the id would
     * otherwise 500. `null` here simply persists the row without an
     * organization relation.
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
     * Resolve a list of tag names to shared {@see Tag} entities.
     *
     * Reuses an existing tag when one already carries the name — so the
     * unique-name constraint holds and the catalogue's tag facet stays
     * deduplicated — and creates a new (unpersisted) tag otherwise; the
     * assistant's cascade persists any new tags on flush. Duplicate names
     * within one submission collapse to a single entity.
     *
     * @param list<string> $names submitted tag names, already trimmed and non-empty
     *
     * @return list<Tag> one entity per distinct name, in first-seen order
     */
    private function resolveTags(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $resolved[$name] ??= $this->tags->findOneByName($name) ?? new Tag($name);
        }

        return array_values($resolved);
    }
}
