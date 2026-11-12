<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Dev;

use App\Controller\AppController;
use App\Module\GitHub\Command\PreviewGitHubPickerSignInCommand;
use App\Module\GitHub\Command\PreviewGitHubPickerSignInHandler;
use App\Module\GitHub\Service\Dev\DevGitHubPickerClient;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Signs the picker in with a preview token, then shows the Repositories settings page with the picker. It exists in dev only. */
#[Route(
    '/dev/github-picker/{id:project}/{scenario}',
    name: 'dev_github_picker',
    requirements: ['scenario' => 'listing|none'],
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewGitHubPickerController extends AppController
{
    public function __construct(
        private readonly PreviewGitHubPickerSignInHandler $signIn,
    ) {
    }

    public function __invoke(Project $project, string $scenario): Response
    {
        ($this->signIn)(new PreviewGitHubPickerSignInCommand(
            $project,
            'listing' === $scenario ? DevGitHubPickerClient::TOKEN_LISTING : DevGitHubPickerClient::TOKEN_NONE,
        ));

        return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
    }
}
