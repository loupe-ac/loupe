<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service\Dev;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * A usage row outlives its run, and the spend of a card counts it. A seeder calls
 * this for each run, stored or new: a restored run links the stored row again, so
 * the spend does not double, and a stored run gets a deleted row back.
 */
#[When('dev')]
#[When('test')]
final readonly class DevRunUsage
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkerRunUsageRepository $workerRunUsages,
    ) {
    }

    /**
     * Call it after the run is persisted, because the row takes its id from the run.
     *
     * @return bool true when the row was missing
     */
    public function record(SeedContext $context, WorkerRun $run, string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens, int $cacheWriteTokens, ?string $costUsd, bool $billedCost = false): bool
    {
        $runId = $run->id ?? throw new \LogicException('A persisted run has an id.');
        $id = DevSeedId::of(\sprintf('bridge/usage/%s/%s', $runId->toRfc4122(), $model));

        $stored = $this->workerRunUsages->find($id);
        if ($stored instanceof WorkerRunUsage) {
            $stored->run = $run;

            return false;
        }

        $usage = new WorkerRunUsage($run, $run->project, $run->subjectType, $run->subjectId, $run->workKind, $model, WorkerRunUsageSource::Reported, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens, $costUsd, $billedCost);
        $context->ids->assign($usage, $id);
        $this->em->persist($usage);

        return true;
    }
}
