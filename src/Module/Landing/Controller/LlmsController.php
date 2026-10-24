<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowLlmsHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/llms.txt',
    name: 'app_llms',
    methods: ['GET'],
)]
final class LlmsController extends AppController
{
    public function __construct(
        private readonly ShowLlmsHandler $showLlms,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response(($this->showLlms)(), Response::HTTP_OK, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }
}
