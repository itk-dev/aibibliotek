<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\OpenWebUiConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared OpenWebUI config validator.
 *
 * The AJAX progress UI consumes `getChecks()` + `runCheck()`; the
 * form-submit path consumes `validate()`. Both flow through the
 * same underlying `validate*` methods.
 */
final class OpenWebUiConfigValidatorTest extends TestCase
{
    /**
     * Build a validator pointed at the real project schema file.
     *
     * Resolved relative to this test so the check runs against the
     * same schema the app ships, without booting the kernel.
     */
    private function validator(): OpenWebUiConfigValidator
    {
        return new OpenWebUiConfigValidator(\dirname(__DIR__, 3).'/config/schema/openwebui-model.json');
    }

    // Verifies getChecks() returns the declared check identifiers in order.
    public function testGetChecksReturnsTheDeclaredOrder(): void
    {
        self::assertSame(['syntax', 'schema'], $this->validator()->getChecks());
    }

    // Tests that runCheck('syntax') dispatches to the syntax validator.
    public function testRunCheckDispatchesToSyntaxValidator(): void
    {
        $validator = $this->validator();

        $valid = $validator->runCheck('syntax', '{"name":"demo"}');
        $invalid = $validator->runCheck('syntax', '{not json');

        self::assertTrue($valid->isValid());
        self::assertFalse($invalid->isValid());
    }

    // Tests that runCheck('schema') dispatches to the schema validator.
    public function testRunCheckDispatchesToSchemaValidator(): void
    {
        $validator = $this->validator();

        $valid = $validator->runCheck('schema', '{"name":"demo"}');
        $invalid = $validator->runCheck('schema', '{"base_model_id":"gpt-4o"}');

        self::assertTrue($valid->isValid());
        self::assertFalse($invalid->isValid());
    }

    // Ensures runCheck() throws InvalidArgumentException for an unknown identifier.
    public function testRunCheckRejectsUnknownIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->runCheck('no-such-check', '{}');
    }

    // Tests that validateSyntax() returns valid for parseable JSON.
    public function testValidateSyntaxAcceptsValidJson(): void
    {
        $result = $this->validator()->validateSyntax('{"name":"demo"}');

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    // Ensures validateSyntax() returns the decoder error when the input does not parse.
    public function testValidateSyntaxRejectsMalformedJson(): void
    {
        $result = $this->validator()->validateSyntax('{not json');

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->getErrors());
        self::assertNotSame('', $result->getErrors()[0]);
    }

    // Verifies validateSchema() accepts the real one-element array export shape.
    public function testValidateSchemaAcceptsArrayWrappedExport(): void
    {
        $json = json_encode([[
            'name' => 'Demo',
            'base_model_id' => 'gpt-4o',
            'params' => ['system' => 'prompt'],
            'meta' => ['description' => 'd', 'tags' => [['name' => 'alpha']]],
        ]], \JSON_THROW_ON_ERROR);

        self::assertTrue($this->validator()->validateSchema($json)->isValid());
    }

    // Verifies validateSchema() accepts the flat single-object and info-wrapped shapes.
    public function testValidateSchemaAcceptsFlatAndInfoShapes(): void
    {
        $validator = $this->validator();

        self::assertTrue($validator->validateSchema('{"name":"demo"}')->isValid());
        self::assertTrue($validator->validateSchema('{"info":{"name":"demo"}}')->isValid());
    }

    // Ensures validateSchema() rejects an empty array — no model to import.
    public function testValidateSchemaRejectsEmptyArray(): void
    {
        $result = $this->validator()->validateSchema('[]');

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
    }

    // Ensures validateSchema() rejects an array holding more than one model.
    public function testValidateSchemaRejectsMultipleModels(): void
    {
        $json = json_encode([['name' => 'a'], ['name' => 'b']], \JSON_THROW_ON_ERROR);

        self::assertFalse($this->validator()->validateSchema($json)->isValid());
    }

    // Ensures validateSchema() rejects a model missing the required `name`.
    public function testValidateSchemaRejectsMissingName(): void
    {
        self::assertFalse($this->validator()->validateSchema('[{"base_model_id":"gpt-4o"}]')->isValid());
    }

    // Ensures validateSchema() rejects a `name` of the wrong type.
    public function testValidateSchemaRejectsNonStringName(): void
    {
        self::assertFalse($this->validator()->validateSchema('[{"name":123}]')->isValid());
    }

    // Ensures validateSchema() reports the decoder error for malformed JSON so it is safe to run standalone.
    public function testValidateSchemaReportsMalformedJson(): void
    {
        $result = $this->validator()->validateSchema('{not json');

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
    }

    // Ensures a missing schema file surfaces as a RuntimeException rather than a silent pass.
    public function testValidateSchemaThrowsWhenSchemaFileMissing(): void
    {
        $validator = new OpenWebUiConfigValidator('/no/such/schema-file.json');

        $this->expectException(\RuntimeException::class);

        $validator->validateSchema('{"name":"demo"}');
    }

    // Ensures a schema file that isn't a JSON object surfaces as a RuntimeException.
    public function testValidateSchemaThrowsWhenSchemaFileIsNotAnObject(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schema');
        self::assertIsString($path);
        file_put_contents($path, '[]');

        try {
            $this->expectException(\RuntimeException::class);
            (new OpenWebUiConfigValidator($path))->validateSchema('{"name":"demo"}');
        } finally {
            unlink($path);
        }
    }

    // Tests that validate() composes a valid result when every check passes.
    public function testValidateAggregatesValidWhenAllChecksPass(): void
    {
        $result = $this->validator()->validate('{"name":"demo"}');

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    // Ensures validate() surfaces every failing check's errors (syntax check fails first).
    public function testValidateAggregatesErrorsFromFailingChecks(): void
    {
        $result = $this->validator()->validate('{not json');

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
    }
}
