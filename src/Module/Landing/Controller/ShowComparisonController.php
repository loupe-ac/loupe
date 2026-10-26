<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowComparisonCommand;
use App\Module\Landing\Command\ShowComparisonHandler;
use App\Module\Landing\Service\ComparisonPages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/compare/{slug}',
    name: 'app_compare',
    requirements: ['slug' => ComparisonPages::SLUG_PATTERN],
    methods: ['GET'],
)]
final class ShowComparisonController extends AppController
{
    public function __construct(
        private readonly ShowComparisonHandler $showComparison,
    ) {
    }

    public function __invoke(string $slug): Response
    {
        $view = ($this->showComparison)(new ShowComparisonCommand($slug)) ?? throw $this->createNotFoundException();

        return $this->render('@Landing/compare/show_comparison.html.twig', ['page' => $view->page, 'others' => $view->others]);
    }
}
