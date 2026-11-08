<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ListFeaturesHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/features',
    name: 'app_features',
    methods: ['GET'],
)]
final class ListFeaturesController extends AppController
{
    public function __construct(
        private readonly ListFeaturesHandler $listFeatures,
    ) {
    }

    public function __invoke(): Response
    {
        $pages = ($this->listFeatures)() ?? throw $this->createNotFoundException();

        return $this->render('@Landing/features/list_features.html.twig', ['pages' => $pages]);
    }
}
