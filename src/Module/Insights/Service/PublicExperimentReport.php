<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Insights\View\PublicExperimentResult;
use App\Module\Project\Entity\Project;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The report of a shared experiment, kept for 15 minutes in the shared cache. The kept result holds the totals of the
 * report and none of its cards, so a visitor never gets a card. The statistics run in the request of the first
 * visitor after the entry expires.
 */
final readonly class PublicExperimentReport
{
    public const int LIFETIME = 900;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ShowExperimentHandler $showExperiment,
        private ClockInterface $clock,
    ) {
    }

    /** @return PublicExperimentResult|null null when the project has no such experiment */
    public function forExperiment(Project $project, string $experiment): ?PublicExperimentResult
    {
        $item = $this->cache->getItem($this->key($project, $experiment));
        $kept = $item->isHit() ? $item->get() : null;
        if ($kept instanceof PublicExperimentResult) {
            return $kept;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $report = ($this->showExperiment)(new ShowExperimentCommand($project, $experiment, limit: 0));
        if (null === $report) {
            return null;
        }

        $result = new PublicExperimentResult($now, $report->experiment, $report->headline, $report->variants, $report->metrics);
        $item->set($result)->expiresAfter(self::LIFETIME);
        $this->cache->save($item);

        return $result;
    }

    /** The locale is part of the key, because the report holds translated texts. */
    public function key(Project $project, string $experiment): string
    {
        return 'insights_public_experiment_'.$project->requireId()->toRfc4122().'_'.hash('sha256', $experiment.'|'.\Locale::getDefault());
    }
}
