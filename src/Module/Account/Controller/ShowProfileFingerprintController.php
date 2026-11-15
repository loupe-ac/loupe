<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\ProfileFingerprint;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The fingerprint of the profile, which an open profile form compares with its own after a reconnect. */
#[Route(
    '/account/profile/fingerprint',
    name: 'app_account_profile_fingerprint',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowProfileFingerprintController extends AppController
{
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return new Response(ProfileFingerprint::of($user->fullName), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
