<?php

declare(strict_types=1);

namespace App\Outbox\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Outbox\Command\SeedActivityEventsCommand;
use App\Outbox\Command\SeedActivityEventsHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only: gives the signed-in user a project named by `name` that holds
 * `published` delivered and `unsent` undelivered outbox events, so a spec can
 * reach page two of the activity list without twenty renames.
 */
#[Route(
    '/dev/seed/activity-events',
    name: 'app_dev_seed_activity_events',
    methods: ['POST'],
)]
#[When('dev')]
final class SeedActivityEventsController extends AppController
{
    public function __construct(
        private readonly SeedActivityEventsHandler $seedActivityEvents,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User; this route must stay behind the ROLE_USER catch-all.', self::class));
        }

        $project = ($this->seedActivityEvents)(new SeedActivityEventsCommand(
            $user,
            $request->request->getString('name') ?: 'e2e-activity',
            max(0, min(100, $request->request->getInt('published'))),
            max(0, min(100, $request->request->getInt('unsent'))),
        ));

        return $this->json(['projectId' => (string) $project->id], JsonResponse::HTTP_CREATED);
    }
}
