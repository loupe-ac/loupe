<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\ReissueBetaInviteCommand;
use App\Module\Billing\Command\Admin\ReissueBetaInviteHandler;
use App\Module\Billing\Entity\BetaInvite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-beta-invite-reissue')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/beta-invites/{id:invite}/reissue',
    name: 'app_admin_beta_invites_reissue',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class ReissueBetaInviteController extends AppController
{
    public function __construct(
        private readonly ReissueBetaInviteHandler $reissueBetaInvite,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, BetaInvite $invite): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s).', self::class, get_debug_type($actor)));
        }

        try {
            $view = ($this->reissueBetaInvite)(new ReissueBetaInviteCommand($invite, $actor));

            $request->getSession()->set(
                ListBetaInvitesController::ISSUED_LINK,
                $this->generateUrl('app_billing_beta_invite', ['token' => $view->token], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $this->addFlash('success', $this->translator->trans('billing.admin.beta_invite.flash.reissued'));
        } catch (DomainErrors $e) {
            // A fieldless form, so a domain failure has no field and becomes a flash.
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_admin_beta_invites_list');
    }
}
