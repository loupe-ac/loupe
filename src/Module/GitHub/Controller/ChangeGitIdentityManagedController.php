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
use Symfony\UX\Turbo\TurboBundle;

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

        $flash = ['severity' => 'error', 'message' => $this->translator->trans('github.identity.flash.invalid')];
        $changed = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $turnOff = $form->get('turnOff');
            $managed = ($turnOff instanceof SubmitButton && $turnOff->isClicked()) ? false : $form->getData()->managed;
            ($this->changeGitIdentityManaged)(new ChangeGitIdentityManagedCommand($project, $managed));
            $changed = true;
            $flash = ['severity' => 'success', 'message' => $this->translator->trans($managed ? 'github.identity.flash.managed' : 'github.identity.flash.unmanaged')];
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                ($changed ? $this->renderView('@GitHub/_git_identity.stream.html.twig', ['project' => $project]) : '')
                .$this->renderView('_flash.stream.html.twig', $flash),
                Response::HTTP_OK,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        $this->addFlash($flash['severity'], $flash['message']);

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'git-identity']);
    }
}
