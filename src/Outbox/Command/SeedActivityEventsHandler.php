<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SeedActivityEventsHandler
{
    public function __construct(
        private EnsureHarnessProjectHandler $ensureHarnessProject,
        private EntityManagerInterface $em,
    ) {
    }

    /** Writes straight to the table, so no live signal goes out for the seeded rows. */
    public function __invoke(SeedActivityEventsCommand $command): Project
    {
        $project = ($this->ensureHarnessProject)(new EnsureHarnessProjectCommand($command->owner, $command->projectName));
        $start = new \DateTimeImmutable('-1 day');

        for ($number = 1; $number <= $command->published + $command->unsent; ++$number) {
            $event = new OutboxEvent(
                project: $project,
                type: 'project.renamed',
                topic: 'dev-seed/activity',
                payload: json_encode(['seed' => $number], \JSON_THROW_ON_ERROR),
                createdAt: $start->modify(\sprintf('+%d seconds', $number)),
            );
            if ($number <= $command->published) {
                $event->publishedAt = $event->createdAt;
            }
            $this->em->persist($event);
        }
        $this->em->flush();

        return $project;
    }
}
