<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * {@see JsonSchemaConfigValidator} bound to the AI-reolen native schema.
 *
 * The generic syntax + schema pipeline lives in the parent; this class
 * only pins the schema document, which accepts the `format: ai-reolen`
 * envelope and its canonical fields.
 */
final class NativeConfigValidator extends JsonSchemaConfigValidator
{
    /**
     * @param string $schemaPath absolute path to the native assistant JSON Schema
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/schema/native-assistant.json')]
        string $schemaPath,
    ) {
        parent::__construct($schemaPath);
    }
}
