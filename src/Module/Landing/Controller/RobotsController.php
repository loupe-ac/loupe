<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Landing\Command\ShowRobotsHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/robots.txt',
    name: 'app_robots',
    methods: ['GET'],
)]
final class RobotsController extends AppController
{
    public function __construct(
        private readonly ShowRobotsHandler $showRobots,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response(($this->showRobots)(), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
