<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowFaqHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/faq',
    name: 'app_faq',
    methods: ['GET'],
)]
final class ShowFaqController extends AppController
{
    public function __construct(
        private readonly ShowFaqHandler $showFaq,
    ) {
    }

    public function __invoke(): Response
    {
        $faq = ($this->showFaq)() ?? throw $this->createNotFoundException();

        return $this->render('@Landing/faq/show_faq.html.twig', ['faq' => $faq]);
    }
}
