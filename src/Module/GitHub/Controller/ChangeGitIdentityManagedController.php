<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\GitHub\Command\ChangeGitIdentityManagedCommand;
use App\Module\GitHub\Command\ChangeGitIdentityManagedHandler;
use App\Module\GitHub\Form\ChangeGitIdentityManagedFormType;
use App\Module\GitHub\Form\ChangeGitIdentityManagedRequest;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/git-identity',
    name: 'app_project_git_identity',
    methods: ['POST'],
)]
class ChangeGitIdentityManagedController extends AppController
{
    public function __construct(
        private readonly ChangeGitIdentityManagedHandler $changeGitIdentityManaged,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $form = $this->createForm(ChangeGitIdentityManagedFormType::class, new ChangeGitIdentityManagedRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $turnOff = $form->get('turnOff');
            $managed = ($turnOff instanceof SubmitButton && $turnOff->isClicked()) ? false : $form->getData()->managed;
            ($this->changeGitIdentityManaged)(new ChangeGitIdentityManagedCommand($project, $managed));
            $this->addFlash('success', $this->translator->trans($managed ? 'github.identity.flash.managed' : 'github.identity.flash.unmanaged'));
        } else {
            $this->addFlash('error', $this->translator->trans('github.identity.flash.invalid'));
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'git-identity']);
    }
}
