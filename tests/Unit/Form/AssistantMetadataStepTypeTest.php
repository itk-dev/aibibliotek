<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Assistant\Model\ModelMap;
use App\Form\AssistantMetadataStepType;
use App\Repository\AssistantRepository;
use App\Repository\OrganizationRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Unit tests for the step-2 metadata form type's view augmentation.
 *
 * finishView() exposes the known-model choices — canonical shortlist
 * unioned with distinct values persisted in the catalogue — plus the
 * per-model alias tokens the client picker uses for search. The field
 * only exists while this is the flow's active step, so the two branches
 * (child present / absent) are covered directly here.
 */
final class AssistantMetadataStepTypeTest extends TestCase
{
    /**
     * Build the form type with a real ModelMap and a stubbed
     * AssistantRepository returning the given persisted values.
     *
     * @param list<string> $persistedModels values persistedLanguageModels() should return
     */
    private function type(array $persistedModels = []): AssistantMetadataStepType
    {
        $repository = $this->createMock(AssistantRepository::class);
        $repository->method('persistedLanguageModels')->willReturn($persistedModels);
        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findAll')->willReturn([]);

        return new AssistantMetadataStepType(
            new ModelMap(\dirname(__DIR__, 3).'/config/model_map.yaml'),
            $repository,
            $organizations,
        );
    }

    // Verifies finishView() exposes the canonical choices and aliases when the field is present.
    public function testFinishViewExposesCanonicalChoicesAndAliases(): void
    {
        $view = new FormView();
        $view->children['languageModel'] = new FormView($view);

        $this->type()->finishView($view, $this->createStub(FormInterface::class), []);

        $vars = $view->children['languageModel']->vars;
        self::assertNotEmpty($vars['model_choices']);
        self::assertContains('gpt-4o', $vars['model_choices'], 'canonical ids appear as choice values');
        self::assertArrayHasKey('gpt-4o', $vars['model_aliases']);
        self::assertContains('openai/gpt-4o', $vars['model_aliases']['gpt-4o']);
    }

    // Ensures persisted values absent from the canonical map are appended so legacy rows stay pickable.
    public function testFinishViewAppendsPersistedExtrasNotInCanonicalMap(): void
    {
        $view = new FormView();
        $view->children['languageModel'] = new FormView($view);

        $this->type(['custom-local-llm'])->finishView(
            $view,
            $this->createStub(FormInterface::class),
            [],
        );

        self::assertContains('custom-local-llm', $view->children['languageModel']->vars['model_choices']);
    }

    // Verifies a persisted value colliding with a canonical id (case-insensitively) does not duplicate the choice — canonical spelling wins.
    public function testFinishViewDedupesCaseInsensitivelyKeepingCanonicalSpelling(): void
    {
        $view = new FormView();
        $view->children['languageModel'] = new FormView($view);

        $this->type(['GPT-4o'])->finishView(
            $view,
            $this->createStub(FormInterface::class),
            [],
        );

        $choices = $view->children['languageModel']->vars['model_choices'];
        $matches = array_filter(
            $choices,
            static fn (string $id): bool => 'gpt-4o' === strtolower($id),
        );
        self::assertCount(1, $matches, 'gpt-4o must appear once');
        self::assertContains('gpt-4o', $matches, 'canonical spelling wins the tie');
    }

    // Verifies finishView() is a no-op when the language-model field is absent (an inactive step).
    public function testFinishViewSkipsWhenFieldAbsent(): void
    {
        $view = new FormView();

        $this->type()->finishView($view, $this->createStub(FormInterface::class), []);

        self::assertSame([], $view->children);
    }
}
