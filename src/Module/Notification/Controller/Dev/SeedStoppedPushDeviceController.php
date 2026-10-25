<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\SeedStoppedPushDeviceCommand;
use App\Module\Notification\Command\SeedStoppedPushDeviceHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only: seeds a push device that stopped working for the signed-in user.
 * The optional label names it, and dismissed=1 also closes its warning.
 */
#[Route(
    '/dev/seed/stopped-push-device',
    name: 'app_dev_seed_stopped_push_device',
    methods: ['POST'],
)]
#[When('dev')]
final class SeedStoppedPushDeviceController extends AppController
{
    public function __construct(
        private readonly SeedStoppedPushDeviceHandler $seedDevice,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User; this route must stay behind the ROLE_USER catch-all.', self::class));
        }

        $device = ($this->seedDevice)(new SeedStoppedPushDeviceCommand(
            $user,
            $request->request->getString('label') ?: 'Safari on iPad',
            new \DateTimeImmutable('2026-10-20 08:00:00'),
            $request->request->getBoolean('dismissed'),
        ));

        return $this->json(['deviceId' => (string) $device->id], JsonResponse::HTTP_CREATED);
    }
}
