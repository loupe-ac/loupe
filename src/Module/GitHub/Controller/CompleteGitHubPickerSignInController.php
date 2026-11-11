<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\SignInGitHubPickerCommand;
use App\Module\GitHub\Command\SignInGitHubPickerHandler;
use App\Module\GitHub\Service\GitHubInstallSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The OAuth callback of the picker sign-in. The project comes from the
 * session, not the route, so the handler runs the voter.
 */
#[Route(
    '/github/app/callback',
    name: 'app_github_app_callback',
    methods: ['GET'],
)]
class CompleteGitHubPickerSignInController extends AppController
{
    public function __construct(
        private readonly GitHubInstallSession $installSession,
        private readonly SignInGitHubPickerHandler $signIn,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $this->installSession->pending();
        if (null === $pending || $pending->hasSignedIn() || null === $pending->authorizeState || null === $pending->codeVerifier) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.expired'));

            return $this->redirectToRoute('app_projects');
        }

        $tab = $this->redirectToRoute('connect' === $pending->startedFrom ? 'app_project_connect' : 'app_project_repositories_settings', ['id' => $pending->projectId]);
        $state = $request->query->get('state');
        if (!\is_string($state) || !hash_equals($pending->authorizeState, $state)) {
            $this->installSession->clear();
            $this->addFlash('error', $this->translator->trans('github.app.flash.state_mismatch'));

            return $tab;
        }

        $code = $request->query->get('code');
        if (!\is_string($code) || '' === $code) {
            $this->installSession->clear();
            $this->addFlash('error', $this->translator->trans('github.app.flash.not_authorized'));

            return $tab;
        }

        try {
            $signedIn = ($this->signIn)(new SignInGitHubPickerCommand(
                $pending,
                $code,
                $this->generateUrl('app_github_app_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (DomainErrors $e) {
            $this->installSession->clear();
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return \array_key_exists('project', $e->errors) ? $this->redirectToRoute('app_projects') : $tab;
        }

        if (null !== $signedIn->installUrl) {
            return $this->redirect($signedIn->installUrl);
        }

        if (null !== $signedIn->connected) {
            $this->addFlash('success', $this->translator->trans('github.picker.flash.connected', ['%repository%' => $signedIn->connected]));

            return $tab;
        }

        if ([] !== $signedIn->refused) {
            $this->addFlash('warning', $this->translator->trans('github.picker.flash.refused', [
                '%count%' => \count($signedIn->refused),
                '%repositories%' => implode(', ', $signedIn->refused),
            ]));

            return $tab;
        }

        return $this->redirectToRoute('app_github_repositories_picker', ['id' => $pending->projectId]);
    }
}
