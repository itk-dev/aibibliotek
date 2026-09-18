<?php

declare(strict_types=1);

namespace App\Form;

use App\Assistant\AssistantDraft;
use App\Assistant\AssistantDraftPrefiller;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Three-step "share an assistant" wizard.
 *
 * Uses Symfony's {@see AbstractFlowType} multi-step form support
 * so state carrying, navigation buttons (Previous / Next /
 * Finish), and validation-group scoping come from the framework
 * rather than hand-rolled session plumbing.
 *
 * Steps:
 *
 * 1. `json`     — user pastes / uploads the assistant config.
 *                 On successful submit the step-1 `POST_SUBMIT`
 *                 listener runs {@see AssistantDraftPrefiller}
 *                 so step 2 opens with the detected format's
 *                 title / description / language model / tags
 *                 pre-filled.
 * 2. `metadata` — review + edit the extracted metadata. Full
 *                 validation of the `metadata` group runs here.
 * 3. `receipt`  — render-only "assistant delt" page with a
 *                 permalink to the created row. The controller
 *                 persists the assistant on the 2 → 3 transition
 *                 and stashes the id on the DTO.
 *
 * State lives in the session (`SessionDataStorage`) under the
 * `assistant_new_flow` key. `auto_reset = true` (the default)
 * means the session slot is cleared when the flow finishes, so
 * hitting `/assistant/new` again after a save starts fresh.
 */
final class AssistantCreateFlowType extends AbstractFlowType
{
    /**
     * Session key {@see SessionDataStorage} uses for this flow.
     * A distinct key namespace keeps the DTO isolated from any
     * future flow the app might add.
     */
    public const string SESSION_KEY = 'assistant_new_flow';

    /**
     * @param RequestStack            $requestStack backing the session storage
     * @param AssistantDraftPrefiller $prefiller    detects the format and pre-populates step 2 from step 1
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AssistantDraftPrefiller $prefiller,
    ) {
    }

    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $builder
            ->addStep('json', AssistantJsonStepType::class)
            ->addStep('metadata', AssistantMetadataStepType::class)
            ->addStep('receipt', AssistantReceiptStepType::class)
            ->add('navigator', AssistantNavigatorFlowType::class)
        ;

        // On step 1 → step 2, the DTO's raw config is fresh. Detect
        // its format and pre-fill the still-empty step 2 fields so the
        // user sees a filled form instead of blanks. Fires against the
        // whole flow after the step 1 form submit resolves.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $draft = $event->getData();
            if ($draft instanceof AssistantDraft && 'json' === $draft->step) {
                $this->prefiller->prefill($draft);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssistantDraft::class,
            'step_property_path' => 'step',
            'data_storage' => new SessionDataStorage(self::SESSION_KEY, $this->requestStack),
        ]);
    }
}
