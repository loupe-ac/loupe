<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Dev;

use App\Controller\AppController;
use App\Module\GitHub\Command\PreviewGitHubInstallChoiceCommand;
use App\Module\GitHub\Command\PreviewGitHubInstallChoiceHandler;
use App\Module\GitHub\Service\Dev\DevGitHubPickerClient;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Signs in to GitHub with a preview token and no project, then shows the project choice. It exists in dev only. */
#[Route(
    '/dev/github-install-choice',
    name: 'dev_github_install_choice',
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewGitHubInstallChoiceController extends AppController
{
    public function __construct(
        private readonly PreviewGitHubInstallChoiceHandler $signIn,
    ) {
    }

    public function __invoke(): Response
    {
        ($this->signIn)(new PreviewGitHubInstallChoiceCommand(DevGitHubPickerClient::TOKEN_LISTING, DevGitHubPickerClient::LISTED_INSTALLATION));

        return $this->redirectToRoute('app_github_app_project');
    }
}
