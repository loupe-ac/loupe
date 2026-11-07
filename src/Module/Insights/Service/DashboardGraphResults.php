<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Keeps each computed graph for 15 minutes in the shared cache, with no language in it. The key holds the project id
 * and a hash of the settings, so a rename, a move or a resize keeps the result and an edit misses. Expired entries
 * leave the table through PruneAppCacheTask.
 */
final readonly class DashboardGraphResults
{
    public const int LIFETIME = 900;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private KeptGraphComputer $computer,
        private TemplateMetrics $templateMetrics,
        private ClockInterface $clock,
    ) {
    }

    /** The kept result, or null. It never computes. */
    public function get(Project $project, InsightsSavedGraph $graph): ?KeptGraphResult
    {
        $item = $this->cache->getItem($this->key($project, $graph));
        $result = $item->isHit() ? $item->get() : null;

        return $result instanceof KeptGraphResult ? $result : null;
    }

    /** Computes the graph, keeps the result and returns it. */
    public function compute(Project $project, InsightsSavedGraph $graph): KeptGraphResult
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        /** @var InputBag<string> $settings */
        $settings = new InputBag($graph->settings());
        $query = MetricsQuery::fromQuery($settings, $this->templateMetrics->forProject($project->requireId()));
        $result = $this->computer->compute(new ShowMetricsCommand($project, $query, $now));
        $item = $this->cache->getItem($this->key($project, $graph));
        $item->set($result)->expiresAfter(self::LIFETIME);
        $this->cache->save($item);

        return $result;
    }

    /** The version prefix changes with the shape of the kept result, so an older entry misses once. */
    public function key(Project $project, InsightsSavedGraph $graph): string
    {
        return 'insights_dashboard_v3_'.$project->requireId()->toRfc4122().'_'.hash('sha256', implode('|', $graph->settings()));
    }
}
