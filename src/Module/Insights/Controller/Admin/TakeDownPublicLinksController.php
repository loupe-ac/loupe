<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Insights\Command\TakeDownPublicLinksCommand;
use App\Module\Insights\Command\TakeDownPublicLinksHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicLinkCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-public-links-take-down')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/public-links/{code:link}/take-down',
    name: 'app_admin_public_links_take_down',
    requirements: ['code' => PublicLinkCode::PATTERN],
    methods: ['POST'],
)]
final class TakeDownPublicLinksController extends AppController
{
    public function __construct(
        private readonly TakeDownPublicLinksHandler $takeDown,
        private readonly AdminReturnTo $returnTo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(PublicLink $link, Request $request): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s).', self::class, get_debug_type($actor)));
        }

        ($this->takeDown)(new TakeDownPublicLinksCommand($link->project, $actor));
        $this->addFlash('success', $this->translator->trans('insights.admin.public_links.flash.taken_down', ['%project%' => $link->project->name]));

        return $this->redirect(
            $this->returnTo->validate('public_links', $request->request->get('returnTo'))
                ?? $this->generateUrl('app_admin_public_links_list'),
        );
    }
}
