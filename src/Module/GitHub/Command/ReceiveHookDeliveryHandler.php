<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\InvalidGitHubDelivery;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\DeliveryReplayGuard;
use App\Module\GitHub\Service\RefusedDeliveries;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A repository hook feeds its own project alone. The project owner knows the
 * secret, so a hook delivery never refuses and never blocks another project.
 * The hook records whether its last delivery verified, which is what tells a
 * person that a secret was pasted wrong.
 */
final readonly class ReceiveHookDeliveryHandler
{
    public function __construct(
        private DeliveryAnnouncer $announcer,
        private RefusedDeliveries $refusals,
        private DeliveryReplayGuard $replays,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReceiveHookDeliveryCommand $command): GitHubDeliveryOutcome
    {
        $hook = $command->hook;

        try {
            $delivery = GitHubDelivery::fromRequest($command->request, $hook->secret);
        } catch (InvalidGitHubDelivery $e) {
            $hook->refused(new \DateTimeImmutable(), $e->reason);
            $this->em->flush();
            $this->refusals->record($e, ['hookId' => (string) $hook->id]);

            return GitHubDeliveryOutcome::Refused;
        }

        $scope = 'hook.'.$hook->id;
        if ($this->replays->seen($scope, $delivery)) {
            $this->logger->info('github.delivery_replayed', ['event' => $delivery->event, 'hookId' => (string) $hook->id]);

            return GitHubDeliveryOutcome::Received;
        }

        $hook->accepted(new \DateTimeImmutable());
        $this->em->flush();

        $repository = $delivery->repository();
        if ('ping' !== $delivery->event && null !== $repository) {
            $this->announcer->announce($hook->project, $repository, $delivery, ForgeRepositorySource::Hook);
        }
        $this->replays->remember($scope, $delivery);

        return GitHubDeliveryOutcome::Received;
    }
}
