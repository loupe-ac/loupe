<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\CheckEmailVerificationTokenCommand;
use App\Module\Account\Command\CheckEmailVerificationTokenHandler;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(
    '/register/verify',
    name: 'app_verify_email',
    methods: ['GET'],
)]
class ShowVerifyEmailController extends AppController
{
    public function __construct(
        private readonly CheckEmailVerificationTokenHandler $checkToken,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->query->get('token');
        $user = ($this->checkToken)(new CheckEmailVerificationTokenCommand(is_string($token) ? $token : null));

        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.verification_invalid'));

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->render('@Account/registration/show_verify_email.html.twig', [
            'email' => $user->email,
            'token' => $token,
        ]);
    }
}
