<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use Psr\Clock\ClockInterface;

/**
 * Reads one keyset page of the host samples of the bridge of a run, from the
 * start of the run to its end, or to now while it runs.
 */
final readonly class PageBridgeHostSamplesHandler
{
    public function __construct(
        private BridgeHostSampleRepository $bridgeHostSamples,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PageBridgeHostSamplesCommand $command): PageBridgeHostSamplesView
    {
        $run = $command->run;
        if (null === $run->bridgeId) {
            return new PageBridgeHostSamplesView([], false, 'The run has no bridge, so it has no host samples.');
        }
        if (null === $run->startedAt) {
            return new PageBridgeHostSamplesView([], false, 'The run has no start, so it has no window of host samples.');
        }

        $ownerId = $run->project->owner->id ?? throw new \LogicException('The project owner has no id.');
        $samples = $this->bridgeHostSamples->findForBridgeBetweenAfter($ownerId, $run->bridgeId, $run->startedAt, $run->endedAt ?? $this->clock->now(), $command->after, $command->limit + 1);

        return new PageBridgeHostSamplesView(\array_slice($samples, 0, $command->limit), \count($samples) > $command->limit);
    }
}
