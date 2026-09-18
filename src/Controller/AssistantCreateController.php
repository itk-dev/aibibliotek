<?php

declare(strict_types=1);

namespace App\Controller;

use App\Assistant\AssistantCreator;
use App\Assistant\AssistantDraft;
use App\Assistant\Format\FormatAdapterRegistry;
use App\Form\AssistantCreateFlowType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssistantCreateController extends AbstractController
{
    /**
     * Catalogue the adapters' technical error strings are looked up in.
     *
     * Kept apart from the `validators` domain so the finite set of
     * decoder and schema messages can be localised without cluttering
     * the general validator catalogue. Anything absent from the
     * catalogue passes through verbatim.
     */
    private const string ERRORS_TRANSLATION_DOMAIN = 'assistant_validation';

    public function __construct(
        private readonly FormatAdapterRegistry $formats,
        private readonly AssistantCreator $creator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/assistant/new', name: 'app_assistant_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // Seed the flow with a fresh draft — SessionDataStorage
        // replaces it with the persisted DTO on subsequent requests,
        // but the initial construction always needs an object to
        // read the `step` property off.
        $flow = $this->createForm(AssistantCreateFlowType::class, new AssistantDraft());
        \assert($flow instanceof FormFlowInterface);

        // A fresh GET landing on the page after a completed run
        // (session still holds a draft with a persisted id) should
        // start over from step 1 rather than showing the previous
        // receipt. The flow's `auto_reset` only fires when the user
        // clicks Finish, so we reset explicitly for anyone who
        // navigates away and comes back.
        if ('GET' === $request->getMethod()) {
            $stored = $flow->getData();
            if ($stored instanceof AssistantDraft && null !== $stored->createdAssistantId) {
                $flow->reset();
                $flow = $this->createForm(AssistantCreateFlowType::class, new AssistantDraft());
                \assert($flow instanceof FormFlowInterface);
            }
        }

        // handleRequest runs the current step's submit + validation.
        // getStepForm() then reads the cursor — if the submit was
        // valid it moves the cursor forward and returns a fresh
        // form for the next step, otherwise it returns the same
        // step's form for re-rendering with errors.
        $flow->handleRequest($request);
        $stepForm = $flow->getStepForm();
        \assert($stepForm instanceof FormFlowInterface);

        // The transition from `metadata` → `receipt` is the commit
        // moment: persist the assistant and stash its id on the
        // DTO. Guarded on `createdAssistantId` so a page refresh
        // in step 3 doesn't re-persist. Step 1's `Assert\Json` and
        // the metadata step's `NotBlank` constraints cover every
        // rejection the deeper `AssistantCreator::create()` would
        // otherwise catch, so no `InvalidAssistantInputException`
        // catch is needed here.
        $draft = $stepForm->getData();
        if ($draft instanceof AssistantDraft
            && 'receipt' === $draft->step
            && null === $draft->createdAssistantId
        ) {
            $assistant = $this->creator->create(
                $draft->title,
                $draft->description,
                $draft->languageModel,
                $draft->framework,
                $draft->tags,
                $draft->sourceConfig,
                $draft->organizationId,
                $draft->tagline,
                $draft->knowledgeDescription,
                $draft->dataSensitivity,
            );
            $draft->createdAssistantId = (string) $assistant->getId();

            // The flow saves the DTO during handleRequest, before
            // we set createdAssistantId. Persist the mutation
            // ourselves so a subsequent GET can tell the wizard
            // has finished and reset the session slot.
            $stepForm->getConfig()->getDataStorage()->save($draft);
        }

        return $this->renderStep($stepForm);
    }

    #[Route(path: '/assistant/new/validate-config', name: 'app_assistant_new_validate_config', methods: ['POST'])]
    public function validateConfig(Request $request): JsonResponse
    {
        /** @var array{json?: string, check?: string} $payload */
        $payload = json_decode((string) $request->getContent(), associative: true) ?? [];
        $json = (string) ($payload['json'] ?? '');
        $check = (string) ($payload['check'] ?? '');

        // Reject a check no registered format defines (a malformed client
        // request); the client only ever sends ids from the union below.
        if (!\in_array($check, $this->configChecks(), true)) {
            return new JsonResponse(
                ['valid' => false, 'errors' => [\sprintf('Unknown check "%s".', $check)]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Format-agnostic: detect which format the payload is, then run the
        // requested check against that adapter. A check the detected format
        // doesn't define (e.g. `schema` for the text-based Ollama Modelfile)
        // counts as passed, so the client's progress steps still complete.
        $adapter = $this->formats->detect($json);
        if (null === $adapter) {
            return new JsonResponse([
                'valid' => false,
                'errors' => [$this->translator->trans('assistant.new.step_json.unrecognised_format')],
            ]);
        }

        if (!\in_array($check, $adapter->getChecks(), true)) {
            return new JsonResponse(['valid' => true, 'errors' => []]);
        }

        $result = $adapter->runCheck($check, $json);

        return new JsonResponse([
            'valid' => $result->isValid(),
            'errors' => array_map(
                fn (string $error): string => $this->translator->trans($error, [], self::ERRORS_TRANSLATION_DOMAIN),
                $result->getErrors(),
            ),
        ]);
    }

    private function renderStep(FormFlowInterface $stepForm): Response
    {
        $status = Response::HTTP_OK;
        if ($stepForm->isSubmitted() && !$stepForm->isValid()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('assistant/new.html.twig', [
            'flow' => $stepForm->createView(),
            'draft' => $stepForm->getData(),
            'checks' => $this->configChecks(),
        ], new Response('', $status));
    }

    /**
     * The union of every registered format's validation checks, in order.
     *
     * Drives the step-1 upload progress bar without assuming a single
     * format: the client walks these check ids, and the validate endpoint
     * skips any that don't apply to the detected format.
     *
     * @return list<string> the deduplicated check identifiers
     */
    private function configChecks(): array
    {
        $checks = [];
        foreach (array_keys($this->formats->all()) as $id) {
            foreach ($this->formats->get($id)->getChecks() as $check) {
                $checks[$check] = true;
            }
        }

        return array_keys($checks);
    }
}
