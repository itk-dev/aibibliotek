<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\Flow\Type\NavigatorFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Wizard navigator with a "preserve-on-Previous" tweak.
 *
 * Symfony's stock {@see NavigatorFlowType} wires the Previous
 * button with `clear_submission: true`, which discards whatever
 * the user just typed on the current step. On the create/edit
 * wizard that turns "Ret konfiguration" (Previous on step 2)
 * into an accidental reset: the curator's step-2 edits vanish
 * on the way back to step 1.
 *
 * Swap the default Previous for one with
 * `clear_submission: false, validate: false` — submitted data
 * lands on the DTO before the cursor moves, but no validation
 * fires (so a half-filled step doesn't trap the curator).
 */
final class AssistantNavigatorFlowType extends NavigatorFlowType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        // `remove` + `add` swaps the button in place. The template
        // renders navigator children by name (`flow.navigator.previous`,
        // `.next`, `.finish`), so the trailing position in the
        // builder's children map doesn't affect the visible order.
        //
        // `validation_groups: false` is the switch that actually skips
        // server-side validation — `validate: false` only sets the
        // `formnovalidate` HTML attribute. Without it, the flow's
        // `getStepForm()` returns early when a required field on the
        // current step is unset (e.g. dataSensitivity when arriving
        // from step 1), and the movePrevious handler never runs.
        $builder->remove('previous');
        $builder->add('previous', PreviousFlowType::class, [
            'clear_submission' => false,
            'validate' => false,
            'validation_groups' => false,
        ]);
    }
}
