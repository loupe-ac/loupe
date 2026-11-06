<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Admin;

use App\Controller\AppController;
use App\Module\Insights\Command\ListPublicLinksCommand;
use App\Module\Insights\Command\ListPublicLinksHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/public-links',
    name: 'app_admin_public_links_list',
    methods: ['GET'],
)]
final class ListPublicLinksController extends AppController
{
    public function __construct(
        private readonly ListPublicLinksHandler $listPublicLinks,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest($request, ListPublicLinksHandler::ALLOWED_SORTS, 'project', 'asc');

        $view = ($this->listPublicLinks)(new ListPublicLinksCommand(
            $listRequest->page,
            $listRequest->sort,
            $listRequest->dir,
            $request->query->getString('q') ?: null,
            $request->query->getString('user') ?: null,
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute('app_admin_public_links_list', [...$request->query->all(), 'page' => $view->clampedPage]);
        }

        return $this->render('@Insights/admin/list_public_links.html.twig', [
            'view' => $view,
            'page' => $listRequest->page,
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
        ]);
    }
}
