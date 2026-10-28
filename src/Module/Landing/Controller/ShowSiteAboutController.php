<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowSiteAboutHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Shares /about with app_about, which answers every host this route does not.
#[Route(
    '/about',
    name: 'app_site_about',
    methods: ['GET'],
    condition: "service('site_host_request').matches(request)",
    priority: 1,
)]
final class ShowSiteAboutController extends AppController
{
    public function __construct(
        private readonly ShowSiteAboutHandler $showSiteAbout,
    ) {
    }

    public function __invoke(): Response
    {
        $page = ($this->showSiteAbout)() ?? throw $this->createNotFoundException();

        return $this->render('@Landing/show_site_about.html.twig', ['page' => $page]);
    }
}
