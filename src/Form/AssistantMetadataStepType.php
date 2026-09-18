<?php

declare(strict_types=1);

namespace App\Form;

use App\Assistant\Model\ModelMap;
use App\Entity\Organization;
use App\Enum\DataSensitivity;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 2 of the assistant-create wizard — "Gennemgang".
 *
 * The metadata fields the user reviews and edits before saving.
 * All fields inherit their initial values from the DTO, which
 * the flow's step-1 `POST_SUBMIT` listener pre-populated from
 * the uploaded config. Validation lives in the `metadata` group
 * so step 1's constraints don't re-run on every step-2 submit.
 *
 * The framework is not chosen here — it is the format the upload
 * was detected as on step 1, recorded on the DTO — so this step
 * carries no framework field.
 *
 * `tags` is a comma-separated textbox transformed to / from the
 * DTO's `list<string>` shape, matching the pattern the pre-flow
 * version of this page used.
 *
 * Field-declaration order below mirrors the on-screen order the
 * template renders: identity (title, tagline) → what the assistant
 * does (description) → what it draws on (knowledge, language model
 * paired with organisation) → how it's classified (tags, data
 * sensitivity).
 */
final class AssistantMetadataStepType extends AbstractType
{
    private const string INPUT_CLASS = 'rounded-lg border border-line bg-surface px-3 py-2 text-base text-ink focus:outline-none focus:ring-2 focus:ring-primary/40';
    private const string LABEL_CLASS = 'block font-medium text-ink';
    private const string ROW_CLASS = 'grid gap-1 text-sm';

    /**
     * @param ModelMap               $modelMap      canonical model catalog backing the picker
     * @param AssistantRepository    $assistants    source of legacy/free-typed language-model values already in use
     * @param OrganizationRepository $organizations backs the organization picker's choice list
     */
    public function __construct(
        private readonly ModelMap $modelMap,
        private readonly AssistantRepository $assistants,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'assistant.new.step_metadata.title_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'assistant.new.step_metadata.title_required',
                        groups: ['metadata'],
                    ),
                ],
                'attr' => ['class' => self::INPUT_CLASS, 'autofocus' => true],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('tagline', TextType::class, [
                'label' => 'assistant.new.step_metadata.tagline_label',
                'help' => 'assistant.new.step_metadata.tagline_help',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'assistant.new.step_metadata.tagline_required',
                        groups: ['metadata'],
                    ),
                ],
                'attr' => ['class' => self::INPUT_CLASS, 'maxlength' => 255],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'assistant.new.step_metadata.description_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'assistant.new.step_metadata.description_required',
                        groups: ['metadata'],
                    ),
                ],
                'attr' => ['class' => self::INPUT_CLASS, 'rows' => 4],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('knowledgeDescription', TextareaType::class, [
                'label' => 'assistant.new.step_metadata.knowledge_description_label',
                'help' => 'assistant.new.step_metadata.knowledge_description_help',
                'required' => false,
                'empty_data' => '',
                'attr' => ['class' => self::INPUT_CLASS, 'rows' => 4],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('languageModel', TextType::class, [
                'label' => 'assistant.new.step_metadata.language_model_label',
                'help' => 'assistant.new.step_metadata.language_model_help',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'assistant.new.step_metadata.language_model_required',
                        groups: ['metadata'],
                    ),
                ],
                // TextType so free-typed values (unknown models) round-
                // trip verbatim; the step-2 template renders a `<select>`
                // in place of the default `<input>` so a Choices.js
                // Stimulus controller can enhance it into a searchable
                // combobox seeded with the canonical shortlist and every
                // previously-persisted value.
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('organizationId', ChoiceType::class, [
                'label' => 'assistant.new.step_metadata.organization_label',
                'help' => 'assistant.new.step_metadata.organization_help',
                'required' => false,
                'placeholder' => 'assistant.new.step_metadata.organization_placeholder',
                'choices' => $this->organizationChoices(),
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('tags', TextType::class, [
                'label' => 'assistant.new.step_metadata.tags_label',
                'help' => 'assistant.new.step_metadata.tags_help',
                'required' => false,
                'empty_data' => '',
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('dataSensitivity', EnumType::class, [
                'class' => DataSensitivity::class,
                'label' => 'assistant.new.step_metadata.data_sensitivity_label',
                'help' => 'assistant.new.step_metadata.data_sensitivity_help',
                'required' => true,
                // No placeholder: expanded radios give no "empty" option,
                // so the curator has to make an explicit pick — a hidden
                // <select> default would otherwise let an assistant ship
                // with whichever case sits first in the enum.
                'expanded' => true,
                'choice_label' => static fn (DataSensitivity $case): string => $case->label(),
                // Surface the longer descriptive copy on each child's
                // `vars.attr` so the template can pair it with the label
                // without dragging the whole enum case through the view
                // (EnumType-expanded children carry a bool `vars.data`,
                // not the case itself).
                'choice_attr' => static fn (DataSensitivity $case): array => [
                    'data-description-key' => $case->description(),
                    'data-example-key' => $case->example(),
                ],
                'constraints' => [
                    new Assert\NotNull(
                        message: 'assistant.new.step_metadata.data_sensitivity_required',
                        groups: ['metadata'],
                    ),
                ],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->get('tags')->addModelTransformer(new CallbackTransformer(
                /**
                 * @param list<string>|null $tags
                 */
                static fn (?array $tags): string => null === $tags ? '' : implode(', ', $tags),
                /** @return list<string> */
                static fn (?string $raw): array => array_values(array_filter(
                    array_map(static fn (string $t): string => trim($t), explode(',', (string) $raw)),
                    static fn (string $t): bool => '' !== $t,
                )),
            ))
        ;
    }

    /**
     * Expose the known-model choices and their aliases to the language-model
     * field so the template can render a searchable picker on top of it.
     *
     * The choice list unions {@see ModelMap::choices()} with the distinct
     * `languageModel` values already persisted in the catalogue, so legacy
     * or curator-typed values remain reachable even when they are not part
     * of the canonical shortlist. Case-insensitive dedup applies, and the
     * canonical spelling wins any tie — the catalog view stays coherent
     * even when the persisted value differs only by case.
     *
     * Aliases are exposed as canonical-id => list<string> so the client can
     * search-match by alias (e.g. typing `openai/gpt-4o` filters to the
     * `gpt-4o` entry) without storing the alias as its own choice.
     *
     * Runs in `finishView` (not `buildView`) because child views only
     * exist once they are built. The flow builds this step's fields only
     * while it is the active step, so the child is absent on other steps'
     * renders — skip it then.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $languageModel = $view->children['languageModel'] ?? null;
        if (null === $languageModel) {
            return;
        }

        $canonicalChoices = $this->modelMap->choices();
        $seen = [];
        $choices = [];
        foreach ($canonicalChoices as $label => $id) {
            $seen[strtolower($id)] = true;
            $choices[$label] = $id;
        }
        foreach ($this->assistants->persistedLanguageModels() as $stored) {
            $key = strtolower($stored);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $choices[$stored] = $stored;
        }

        $aliases = [];
        foreach ($canonicalChoices as $id) {
            $aliases[$id] = $this->modelMap->aliasesFor($id);
        }

        $languageModel->vars['model_choices'] = $choices;
        $languageModel->vars['model_aliases'] = $aliases;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'validation_groups' => ['Default', 'metadata'],
        ]);
    }

    /**
     * Build the `<option>` list for the organization picker.
     *
     * Each seeded {@see Organization} contributes `name => ULID (string)`
     * so the DTO's `organizationId` — a ULID string — round-trips
     * through the form. Organisations are ordered by name so the
     * picker reads alphabetically.
     *
     * @return array<string, string> `name => ULID` in name-A→Å order
     */
    private function organizationChoices(): array
    {
        $organizations = $this->organizations->findAll();
        usort(
            $organizations,
            static fn (Organization $a, Organization $b): int => strcasecmp($a->getName(), $b->getName()),
        );

        $choices = [];
        foreach ($organizations as $organization) {
            $choices[$organization->getName()] = (string) $organization->getId();
        }

        return $choices;
    }
}
