<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/repositories',
    name: 'app_project_repositories_settings',
    methods: ['GET'],
)]
final class ShowRepositoriesSettingsController extends AppController
{
    public function __invoke(Project $project): Response
    {
        return $this->render('@Project/show_repositories_settings.html.twig', ['project' => $project]);
    }
}
