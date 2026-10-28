<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Command\DownloadRepositoryRulesetCommand;
use App\Module\GitHub\Command\DownloadRepositoryRulesetHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubRulesetFile;
use App\Module\Project\Entity\Project;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The ruleset that the guide asks the owner to import on GitHub. */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/repositories/{repositoryId}/loupe-ruleset.json',
    name: 'app_github_repository_ruleset',
    requirements: ['repositoryId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class DownloadGitHubRulesetController extends AppController
{
    public function __construct(
        private readonly DownloadRepositoryRulesetHandler $downloadRuleset,
    ) {
    }

    public function __invoke(
        Project $project,
        // The alias moves the raw project id to `project`, and scoping to it makes a foreign id a 404.
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(repositoryId, project)')] ForgeRepository $repository,
    ): Response {
        $json = ($this->downloadRuleset)(new DownloadRepositoryRulesetCommand($repository)) ?? throw $this->createNotFoundException();

        return new Response($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, GitHubRulesetFile::FILE_NAME),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
