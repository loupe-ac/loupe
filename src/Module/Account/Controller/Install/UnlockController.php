<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\UnlockInstallCommand;
use App\Module\Account\Command\UnlockInstallHandler;
use App\Module\Account\Form\InstallUnlockFormType;
use App\Module\Account\Form\InstallUnlockRequest;
use App\Module\Account\Service\InstallAccessGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Takes INSTALL_TOKEN through a POST body, so the token never reaches the
 * access log or the browser history the way a query string would.
 */
#[Route(
    '/install/unlock',
    name: 'app_install_unlock',
    methods: ['GET', 'POST'],
)]
final class UnlockController extends AppController
{
    public function __construct(
        private readonly InstallAccessGuard $installAccessGuard,
        private readonly UnlockInstallHandler $unlockInstall,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'limiter.install_unlock')]
        private readonly RateLimiterFactory $installUnlockLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->installAccessGuard->ensureOpen();

        if ($this->installAccessGuard->isUnlocked($request)) {
            return $this->redirectToRoute('app_install_flags');
        }

        $form = $this->createForm(InstallUnlockFormType::class, $data = new InstallUnlockRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->installUnlockLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $this->auditor->record('account.install_unlock_throttled', AuditOutcome::Refused);
                $form->addError(new FormError($this->translator->trans('account.install.unlock.error.rate_limited')));

                return $this->renderFormResponse('@Account/install/unlock.html.twig', $form)
                    ->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            }

            try {
                ($this->unlockInstall)(new UnlockInstallCommand($request->getSession(), $data->token ?? ''));

                return $this->redirectToRoute('app_install_flags');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Account/install/unlock.html.twig', $form);
    }
}
