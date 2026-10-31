<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphCap;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RestoreSavedGraphHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_undo_puts_the_graph_back_in_its_place(): void
    {
        $project = $this->scenarioProject('undo-ok');
        $this->seedSavedGraph($project, 'A', 0);
        $removed = $this->seedSavedGraph($project, 'B', 1);
        $this->seedSavedGraph($project, 'C', 2);
        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($removed));

        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($removed));

        $this->em()->clear();
        $stored = $this->service(InsightsSavedGraphRepository::class)->findActive($this->em()->find($project::class, $project->id) ?? throw new \LogicException());
        self::assertSame(['A', 'B', 'C'], array_map(static fn (InsightsSavedGraph $graph): string => $graph->name, $stored));
    }

    public function test_an_undo_of_a_graph_that_is_back_changes_nothing(): void
    {
        $project = $this->scenarioProject('undo-twice');
        $graph = $this->seedSavedGraph($project, 'A', 0);

        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graph));

        $this->em()->clear();
        self::assertNull($this->em()->find(InsightsSavedGraph::class, $graph->id)?->removedAt);
    }

    public function test_an_undo_at_the_cap_is_refused_and_the_graph_stays_removed(): void
    {
        $project = $this->scenarioProject('undo-cap');
        $graphs = [];
        for ($i = 0; $i < 12; ++$i) {
            $graphs[] = $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($graphs[4]));
        $this->service(SaveSavedGraphHandler::class)(new SaveSavedGraphCommand($project, 'Replacement', ['metric' => 'cost', 'bucket' => MetricBucket::Day->value]));
        $removedId = $graphs[4]->id;

        try {
            $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graphs[4]));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => SavedGraphCap::ERROR_KEY], $e->errors);
            self::assertSame(['name' => ['%limit%' => 12]], $e->parameters);
        }

        self::assertTrue($this->em()->isOpen());
        $this->em()->clear();
        self::assertNotNull($this->em()->find(InsightsSavedGraph::class, $removedId)?->removedAt);
        self::assertSame(12, $this->service(InsightsSavedGraphRepository::class)->countActive($this->em()->find($project::class, $project->id) ?? throw new \LogicException()));
    }

    public function test_an_undo_after_the_undo_window_is_a_not_found_and_the_graph_stays_removed(): void
    {
        $project = $this->scenarioProject('undo-expired');
        $graph = $this->seedSavedGraph($project, 'A', 0, new \DateTimeImmutable('-25 hours'));
        $graphId = $graph->id;

        try {
            $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graph));
            self::fail('Expected a not found.');
        } catch (NotFoundHttpException) {
        }

        $this->em()->clear();
        self::assertNotNull($this->em()->find(InsightsSavedGraph::class, $graphId)?->removedAt);
    }

    public function test_an_undo_of_a_deleted_graph_is_a_not_found(): void
    {
        $project = $this->scenarioProject('undo-deleted');
        $graph = $this->seedSavedGraph($project, 'A', 0, new \DateTimeImmutable('-2 days'));
        $this->em()->getConnection()->executeStatement('DELETE FROM insights_saved_graphs WHERE id = :id', ['id' => (string) $graph->id]);

        $this->expectException(NotFoundHttpException::class);
        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graph));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
