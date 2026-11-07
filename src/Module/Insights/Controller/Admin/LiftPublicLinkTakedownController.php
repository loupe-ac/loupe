<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Admin;

use App\Controller\AppController;
use App\Module\Insights\Command\LiftPublicLinkTakedownCommand;
use App\Module\Insights\Command\LiftPublicLinkTakedownHandler;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-public-links-lift')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/public-links/projects/{id:project}/lift',
    name: 'app_admin_public_links_lift',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class LiftPublicLinkTakedownController extends AppController
{
    public function __construct(
        private readonly LiftPublicLinkTakedownHandler $lift,
        private readonly AdminReturnTo $returnTo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        ($this->lift)(new LiftPublicLinkTakedownCommand($project));
        $this->addFlash('success', $this->translator->trans('insights.admin.public_links.flash.lifted', ['%project%' => $project->name]));

        return $this->redirect(
            $this->returnTo->validate('public_links', $request->request->get('returnTo'))
                ?? $this->generateUrl('app_admin_public_links_list'),
        );
    }
}
