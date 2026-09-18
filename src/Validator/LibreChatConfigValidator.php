<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * {@see JsonSchemaConfigValidator} bound to the LibreChat preset schema.
 *
 * The generic syntax + schema pipeline lives in the parent; this class
 * only pins the schema document, which recognises a preset by its
 * preset-specific keys.
 */
final class LibreChatConfigValidator extends JsonSchemaConfigValidator
{
    /**
     * @param string $schemaPath absolute path to the LibreChat preset JSON Schema
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/schema/librechat-preset.json')]
        string $schemaPath,
    ) {
        parent::__construct($schemaPath);
    }
}
