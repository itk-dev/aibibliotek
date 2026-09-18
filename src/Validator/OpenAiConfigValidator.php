<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * {@see JsonSchemaConfigValidator} bound to the OpenAI assistant schema.
 *
 * The generic syntax + schema pipeline lives in the parent; this class
 * only pins the schema document, which requires the `object: assistant`
 * const and a `model`.
 */
final class OpenAiConfigValidator extends JsonSchemaConfigValidator
{
    /**
     * @param string $schemaPath absolute path to the OpenAI assistant JSON Schema
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/schema/openai-assistant.json')]
        string $schemaPath,
    ) {
        parent::__construct($schemaPath);
    }
}
