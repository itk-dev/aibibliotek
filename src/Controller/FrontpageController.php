<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AssistantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontpageController extends AbstractController
{
    public function __construct(private readonly AssistantRepository $assistants)
    {
    }

    #[Route('/', name: 'app_frontpage', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('frontpage/index.html.twig', [
            'assistants' => $this->assistants->findBy([], ['id' => 'DESC'], 5),
            'stats' => [
                'assistants' => $this->assistants->count([]),
                // TODO: derive from `OrganizationRepository::count()` once
                // ADR 005 / #65 lands the Organization entity. For now we
                // surface the static count that matches what AssistantFixtures
                // seeds across its detailed + generated entries.
                'organizations' => 10,
                'models' => $this->assistants->countDistinctLanguageModels(),
            ],
        ]);
    }
}
