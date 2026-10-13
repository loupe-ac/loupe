<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowSitemapHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/sitemap.xml',
    name: 'app_sitemap',
    methods: ['GET'],
)]
final class SitemapController extends AppController
{
    public function __construct(
        private readonly ShowSitemapHandler $showSitemap,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response(($this->showSitemap)(), Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
