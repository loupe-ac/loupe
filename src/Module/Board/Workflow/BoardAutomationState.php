<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BoardSettings;
use App\Module\Workflow\Contract\SweepProjectRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(BoardSettings::class)]
final readonly class BoardAutomationState implements BoardSettings
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private SweepProjectRows $sweepRows,
    ) {
    }

    #[\Override]
    public function automationEnabled(Uuid $projectId): bool
    {
        return $this->settings($projectId)['enabled'];
    }

    #[\Override]
    public function stuckDelayMinutes(Uuid $projectId): int
    {
        return $this->settings($projectId)['stuckDelayMinutes'];
    }

    /** @return array{enabled: bool, stuckDelayMinutes: int} */
    private function settings(Uuid $projectId): array
    {
        return $this->sweepRows->remember('board-automation:'.$projectId->toRfc4122(), function () use ($projectId): array {
            $settings = $this->automation->settingsOf($this->project($projectId));

            return ['enabled' => $settings->enabled, 'stuckDelayMinutes' => $settings->stuckDelayMinutes];
        });
    }

    private function project(Uuid $projectId): Project
    {
        return $this->em->getReference(Project::class, $projectId) ?? throw new \LogicException('The project is stored.');
    }
}
