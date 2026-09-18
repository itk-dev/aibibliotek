<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Form\OrganizationType;
use App\Repository\OrganizationRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(Roles::ADMIN)]
#[Route(path: '/admin/organization', name: 'app_admin_organization_')]
final class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/organization/list.html.twig', [
            'organizations' => $this->repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(OrganizationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Organization $organization */
            $organization = $form->getData();
            $this->entityManager->persist($organization);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.organization.flash.created');

            return $this->redirectToRoute('app_admin_organization_index');
        }

        // 422 on invalid submit so Turbo / browsers re-render the form with
        // errors instead of caching the POST as a successful page.
        return $this->render('admin/organization/new.html.twig', [
            'form' => $form,
        ], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route(path: '/{id}/edit', name: 'edit', requirements: ['id' => Requirement::ULID], methods: ['GET', 'POST'])]
    public function edit(Organization $organization, Request $request): Response
    {
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.organization.flash.updated');

            return $this->redirectToRoute('app_admin_organization_index');
        }

        // 422 on invalid submit so Turbo / browsers re-render the form with
        // errors instead of caching the POST as a successful page.
        return $this->render('admin/organization/edit.html.twig', [
            'organization' => $organization,
            'form' => $form,
        ], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => Requirement::ULID], methods: ['POST'])]
    public function delete(Organization $organization, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-organization-delete', (string) $request->request->get('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($organization);
        $this->entityManager->flush();

        $this->addFlash('success', 'admin.organization.flash.deleted');

        return $this->redirectToRoute('app_admin_organization_index');
    }
}
