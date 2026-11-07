<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Admin;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowPublicLinkTakedownCommand;
use App\Module\Insights\Command\ShowPublicLinkTakedownHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicLinkCode;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The page an admin reaches from the bar of a public page. It names the project and asks to confirm. */
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/public-links/{code:link}/take-down',
    name: 'app_admin_public_links_take_down_confirm',
    requirements: ['code' => PublicLinkCode::PATTERN],
    methods: ['GET'],
)]
final class ConfirmTakeDownPublicLinksController extends AppController
{
    public function __construct(
        private readonly ShowPublicLinkTakedownHandler $showTakedown,
    ) {
    }

    public function __invoke(PublicLink $link): Response
    {
        return $this->render('@Insights/admin/confirm_take_down_public_links.html.twig', [
            'view' => ($this->showTakedown)(new ShowPublicLinkTakedownCommand($link)),
        ]);
    }
}
