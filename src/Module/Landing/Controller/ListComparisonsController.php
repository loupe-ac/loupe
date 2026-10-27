<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ListComparisonsHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/compare',
    name: 'app_compare_list',
    methods: ['GET'],
)]
final class ListComparisonsController extends AppController
{
    public function __construct(
        private readonly ListComparisonsHandler $listComparisons,
    ) {
    }

    public function __invoke(): Response
    {
        $pages = ($this->listComparisons)() ?? throw $this->createNotFoundException();

        return $this->render('@Landing/compare/list_comparisons.html.twig', ['pages' => $pages]);
    }
}
