<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ListApiKeysCommand;
use App\Module\OAuth\Command\ListApiKeysHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The key list of the API keys page, which the open page reads again after a signal. */
#[Route(
    '/account/api-keys/frame',
    name: 'app_account_api_keys_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListApiKeysFrameController extends AppController
{
    public function __construct(
        private readonly ListApiKeysHandler $listApiKeys,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@OAuth/_api_keys_frame.html.twig', [
            'view' => ($this->listApiKeys)(new ListApiKeysCommand($user)),
        ]);
    }
}
