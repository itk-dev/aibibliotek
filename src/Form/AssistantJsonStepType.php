<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\ValidAssistantConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 1 of the assistant-create wizard — paste or upload a config.
 *
 * A single textarea holding the assistant configuration in any
 * supported format; the format is detected on submit, so no format
 * choice is asked here. The dropzone / file input is a client-side
 * enhancement layered on top by the `assistant-config-upload` Stimulus
 * controller; the browser still submits the config as the textarea's
 * value, so no separate form field is needed here.
 *
 * `sourceConfig` maps to {@see \App\Assistant\AssistantDraft::$sourceConfig}
 * so state persists across step transitions via
 * `SessionDataStorage`. Validation lives in the `json` group so
 * step 2 (which uses the `metadata` group) doesn't re-check the
 * blob every navigation.
 */
final class AssistantJsonStepType extends AbstractType
{
    private const string INPUT_CLASS = 'rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/40';
    private const string LABEL_CLASS = 'block font-medium text-ink';
    private const string ROW_CLASS = 'grid gap-1 text-sm';

    /**
     * A minimal OpenWebUI export shown as the textarea's placeholder.
     *
     * First-time curators had no way to see what the field expects
     * before submitting something and being told it was wrong. The
     * shape is the OpenWebUI array-of-one-model envelope the catalogue
     * is built around, trimmed to the fields the wizard actually reads,
     * so it doubles as the reference the rejection message points at.
     * Placeholder only — it is never submitted as data.
     */
    private const string EXAMPLE_CONFIG = <<<'JSON'
        [
          {
            "id": "borgerservice-vejviser",
            "name": "Borgerservice-vejviser",
            "base_model_id": "gpt-4o",
            "params": {
              "system": "Du hjælper sagsbehandlere med at finde den rigtige paragraf."
            },
            "meta": {
              "description": "Finder relevante lovhjemler ud fra en kort sagsbeskrivelse.",
              "tags": [{ "name": "borgerservice" }, { "name": "jura" }]
            }
          }
        ]
        JSON;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('sourceConfig', TextareaType::class, [
            'label' => 'assistant.new.step_json.textarea_label',
            'help' => 'assistant.new.step_json.textarea_help',
            'required' => true,
            'empty_data' => '',
            'constraints' => [
                new Assert\NotBlank(
                    message: 'assistant.new.step_json.required',
                    groups: ['json'],
                ),
                new ValidAssistantConfig(groups: ['json']),
            ],
            // Bubble the config's validation errors up to the flow root
            // so the wizard's shell renders them once in its alert box
            // instead of rendering them there AND next to the textarea.
            // The parent step has `inherit_data: true`, whose default
            // `error_bubbling` is true, so the errors continue past the
            // step compound and land on `flow` automatically.
            'error_bubbling' => true,
            'attr' => [
                'class' => self::INPUT_CLASS,
                'rows' => 10,
                'spellcheck' => 'false',
                'placeholder' => self::EXAMPLE_CONFIG,
            ],
            'label_attr' => ['class' => self::LABEL_CLASS],
            'row_attr' => ['class' => self::ROW_CLASS],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'validation_groups' => ['Default', 'json'],
        ]);
    }
}
