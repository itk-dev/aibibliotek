<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * {@see JsonSchemaConfigValidator} bound to the OpenWebUI model schema.
 *
 * The generic syntax + schema pipeline lives in the parent; this class
 * only pins the schema document. That schema accepts the three shapes an
 * OpenWebUI export arrives in — a one-element array, a flat model object,
 * or an `info`-wrapped object — and rejects arrays that don't hold
 * exactly one model.
 */
final class OpenWebUiConfigValidator extends JsonSchemaConfigValidator
{
    /**
     * @param string $schemaPath absolute path to the OpenWebUI model JSON Schema
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/schema/openwebui-model.json')]
        string $schemaPath,
    ) {
        parent::__construct($schemaPath);
    }
}
