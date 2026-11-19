<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Command\RegisterAndVerifyCommand;
use App\Module\Account\Command\RegisterAndVerifyHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\DisplayNameDeriver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only endpoint that registers a user and immediately marks their email as verified.
 * Used exclusively by Playwright e2e tests — not available in production (When('dev')).
 * With `login=1` and a matching password it also signs the user in.
 */
#[Route(
    '/dev/register-and-verify',
    name: 'app_dev_register_and_verify',
    methods: ['POST'],
)]
#[When('dev')]
final class RegisterAndVerifyController extends AppController
{
    public function __construct(
        private readonly RegisterAndVerifyHandler $registerAndVerify,
        private readonly DisplayNameDeriver $displayNameDeriver,
        private readonly Security $security,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $email = $request->request->getString('email');
        $password = $request->request->getString('password');

        if ('' === $email || '' === $password) {
            return $this->json(['error' => 'email and password are required'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The browser fills this field on the real form; a caller that skips it
        // gets the same value the registration page would have suggested. No
        // form guards the length here.
        $fullName = mb_substr($request->request->getString('fullName'), 0, User::MAX_FULL_NAME_LENGTH);
        if ('' === $fullName) {
            $fullName = $this->displayNameDeriver->derive($email);
        }

        $view = ($this->registerAndVerify)(new RegisterAndVerifyCommand(
            email: $email,
            fullName: $fullName,
            plainPassword: $password,
        ));

        if (null === $view->user) {
            return $this->json(
                ['error' => 'Registration failed', 'errors' => $view->errors->errors ?? []],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // The handler reuses an account on a repeated email without a password
        // check, so the session is given only after the password matches.
        if ('1' === $request->request->getString('login')) {
            if (!$this->passwordHasher->isPasswordValid($view->user, $password)) {
                return $this->json(['error' => 'Invalid credentials'], JsonResponse::HTTP_FORBIDDEN);
            }
            $this->security->login($view->user, 'form_login', 'main');
        }

        return $this->json(['email' => $view->user->email], JsonResponse::HTTP_OK);
    }
}
