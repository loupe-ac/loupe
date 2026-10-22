<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Only a signed-out visitor sees the home page, so the preview signs the reader out first. */
#[When('dev')]
final readonly class PreviewWaitlistJoinedHandler
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private TokenStorageInterface $tokenStorage,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(PreviewWaitlistJoinedCommand $command): PreviewWaitlistJoinedView
    {
        if (null !== $this->tokenStorage->getToken()) {
            $this->tokenStorage->setToken(null);
            $this->requestStack->getSession()->invalidate();
        }

        return new PreviewWaitlistJoinedView(
            frame: $command->frame,
            notifyForm: $this->formFactory->create(WaitlistJoinFormType::class, new WaitlistJoinRequest())->createView(),
            modalForm: $this->formFactory->createNamed(WaitlistJoinFormType::MODAL_NAME, WaitlistJoinFormType::class, new WaitlistJoinRequest())->createView(),
        );
    }
}
