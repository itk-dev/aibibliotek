<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Assert the annotated string is a config that some registered
 * format adapter recognises and accepts.
 *
 * Applied to the step-1 textarea in
 * {@see \App\Form\AssistantJsonStepType} so format validation runs
 * server-side, not only in the `assistant-config-upload` Stimulus
 * controller (which a hand-crafted POST could bypass). The check is
 * delegated to {@see \App\Assistant\Format\FormatAdapterRegistry} so
 * the form gate and every adapter share one definition of "valid".
 *
 * Blank values are ignored — pair with `NotBlank` if the field must
 * be present.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ValidAssistantConfig extends Constraint
{
    /**
     * @var string translation key rendered when no registered format accepts the payload
     */
    public string $message = 'assistant.new.step_json.invalid_config';

    /**
     * @var string translation key for the follow-up line naming the accepted formats
     */
    public string $helpMessage = 'assistant.new.step_json.invalid_config_help';
}
