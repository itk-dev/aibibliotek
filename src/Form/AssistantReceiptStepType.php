<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 3 of the assistant-create wizard — "Kvittering".
 *
 * A no-field render-only step. The controller has already
 * persisted the assistant and stashed its id on the DTO by the
 * time this step is shown, so the template just reads
 * `AssistantDraft::$createdAssistantId` to build a permalink.
 */
final class AssistantReceiptStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // No fields — the step exists purely for the receipt view.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'validation_groups' => ['Default'],
        ]);
    }
}
