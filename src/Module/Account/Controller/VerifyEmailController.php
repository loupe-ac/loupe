<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\VerifyEmailCommand;
use App\Module\Account\Command\VerifyEmailHandler;
use App\Module\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('verify-email')]
#[Route(
    '/register/verify',
    name: 'app_verify_email_submit',
    methods: ['POST'],
)]
class VerifyEmailController extends AppController
{
    public function __construct(
        private readonly VerifyEmailHandler $verifyEmail,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->request->get('token');
        $user = ($this->verifyEmail)(new VerifyEmailCommand(is_string($token) ? $token : null));

        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.verification_invalid'));

            return $this->redirectToRoute('app_register_check_email');
        }

        $current = $this->getUser();
        $signedInAsUser = $current instanceof User && (string) $current->id === (string) $user->id;
        $createdHere = $request->getSession()->get('registration_email') === $user->email;

        if ($signedInAsUser || $createdHere) {
            return $this->security->login($user, 'form_login', 'main')
                ?? $this->redirectToRoute('app_home');
        }

        $this->addFlash('success', $this->translator->trans('account.registration.flash.verified_sign_in'));

        return $this->redirectToRoute($current instanceof User ? 'app_home' : 'app_login');
    }
}
