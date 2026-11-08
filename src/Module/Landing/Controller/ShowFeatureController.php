<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Landing\Command\ShowFeatureCommand;
use App\Module\Landing\Command\ShowFeatureHandler;
use App\Module\Landing\Service\FeaturePages;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/features/{slug}',
    name: 'app_feature',
    requirements: ['slug' => FeaturePages::SLUG_PATTERN],
    methods: ['GET'],
)]
final class ShowFeatureController extends AppController
{
    public function __construct(
        private readonly ShowFeatureHandler $showFeature,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function __invoke(string $slug): Response
    {
        $view = ($this->showFeature)(new ShowFeatureCommand($slug)) ?? throw $this->createNotFoundException();

        return $this->render('@Landing/features/show_feature.html.twig', [
            'page' => $view->page,
            'others' => $view->others,
            'currentFeature' => $view->page->key,
            'modalForm' => $this->formFactory->createNamed(WaitlistJoinFormType::MODAL_NAME, WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                'action' => $this->generateUrl('app_waitlist_modal'),
            ])->createView(),
        ]);
    }
}
