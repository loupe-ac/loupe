<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page for a person whose GitHub install is a request that an organization owner must approve. The setup URL
 * forwards to it.
 */
#[Route(
    '/github/app/waiting',
    name: 'app_github_app_waiting',
    methods: ['GET'],
)]
class ShowGitHubInstallRequestWaitingController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@GitHub/show_github_install_request_waiting.html.twig');
    }
}
