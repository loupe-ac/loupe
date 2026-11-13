<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Command\SetExperimentPublicLinkCommand;
use App\Module\Insights\Command\SetExperimentPublicLinkHandler;
use App\Module\Insights\Command\SetGraphPublicLinkCommand;
use App\Module\Insights\Command\SetGraphPublicLinkHandler;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Messenger\DrawPublicPreview;
use App\Module\Insights\Messenger\RefreshInsightsItem;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** The actions that ask the worker for a result at once. */
final class InsightsRefreshRequestsTest extends KernelTestCase
{
    use InsightsScenario;

    private const array SETTINGS = [
        'metric' => 'cost',
        'unit' => 'run',
        'statistic' => 'sum',
        'group' => 'none',
        'range' => 'thirty-days',
        'bucket' => 'day',
        'compare' => 'previous',
        'chart' => 'bars',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->transport()->reset();
    }

    public function test_a_saved_graph_asks_for_its_result(): void
    {
        $project = $this->scenarioProject('ask-save');

        $graph = $this->service(SaveSavedGraphHandler::class)(new SaveSavedGraphCommand($project, 'Cost', self::SETTINGS))->graph;

        $this->assertAskedForGraph($graph);
    }

    public function test_an_update_that_changes_the_settings_asks_for_a_new_result(): void
    {
        $project = $this->scenarioProject('ask-update');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);

        $this->service(UpdateSavedGraphFieldsHandler::class)(new UpdateSavedGraphFieldsCommand($project, (string) $graph->id, settings: self::SETTINGS));

        $this->assertAskedForGraph($graph);
    }

    public function test_an_update_that_changes_only_the_name_asks_for_nothing(): void
    {
        $project = $this->scenarioProject('ask-rename');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $settings = $graph->settings();

        $this->service(UpdateSavedGraphFieldsHandler::class)(new UpdateSavedGraphFieldsCommand($project, (string) $graph->id, name: 'Renamed', settings: $settings));

        self::assertSame($settings, $graph->settings());
        self::assertSame('Renamed', $graph->name);
        self::assertSame([], $this->transport()->getSent());
    }

    public function test_a_restore_asks_when_the_graph_holds_no_result(): void
    {
        $project = $this->scenarioProject('ask-restore');
        $graph = $this->seedSavedGraph($project, 'Cost', 0, new \DateTimeImmutable('-1 minute'));

        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graph));

        $this->assertAskedForGraph($graph);
    }

    public function test_a_restore_asks_for_nothing_when_the_kept_result_still_fits(): void
    {
        $project = $this->scenarioProject('ask-restore-kept');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $this->service(InsightsResultKeeper::class)->graph($graph);
        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($graph));

        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($graph));

        self::assertSame([], $this->transport()->getSent());
    }

    public function test_sharing_a_graph_asks_and_stopping_the_share_does_not(): void
    {
        $project = $this->scenarioProject('ask-share-graph');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $graph->metric = 'cost';
        $this->em()->flush();

        $this->service(SetGraphPublicLinkHandler::class)(new SetGraphPublicLinkCommand($graph, true));
        $this->assertAskedForGraph($graph, 2);
        self::assertInstanceOf(DrawPublicPreview::class, $this->transport()->getSent()[1]->getMessage());

        $this->transport()->reset();
        $this->service(SetGraphPublicLinkHandler::class)(new SetGraphPublicLinkCommand($graph, false));
        self::assertSame([], $this->transport()->getSent());
    }

    public function test_sharing_an_experiment_asks_for_its_report(): void
    {
        $project = $this->scenarioProject('ask-share-experiment');
        $this->seedExperiment($project, 'trial');

        $this->service(SetExperimentPublicLinkHandler::class)(new SetExperimentPublicLinkCommand($project, 'trial', true, null));

        $sent = $this->transport()->getSent();
        self::assertCount(2, $sent);
        self::assertInstanceOf(DrawPublicPreview::class, $sent[1]->getMessage());
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshInsightsItem::class, $message);
        self::assertSame('trial', $message->experiment);
        self::assertNull($message->graphId);
        self::assertSame((string) $project->id, $message->projectId);
    }

    public function test_a_second_request_for_a_queued_item_is_dropped(): void
    {
        $project = $this->scenarioProject('ask-dedupe');
        $graph = $this->seedSavedGraph($project, 'Cost', 0);
        $requester = $this->service(InsightsRefreshRequester::class);

        $requester->graph($graph);
        $requester->graph($graph);
        $requester->graph($graph);

        self::assertCount(1, $this->transport()->getSent());
    }

    public function test_the_requests_of_two_items_both_queue(): void
    {
        $project = $this->scenarioProject('ask-two');
        $first = $this->seedSavedGraph($project, 'One', 0);
        $second = $this->seedSavedGraph($project, 'Two', 1);
        $requester = $this->service(InsightsRefreshRequester::class);

        $requester->graph($first);
        $requester->graph($second);
        $requester->experiment($project, 'trial');

        self::assertCount(3, $this->transport()->getSent());
    }

    private function assertAskedForGraph(InsightsSavedGraph $graph, int $messages = 1): void
    {
        $sent = $this->transport()->getSent();
        self::assertCount($messages, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshInsightsItem::class, $message);
        self::assertSame((string) $graph->id, $message->graphId);
        self::assertSame((string) $graph->project->id, $message->projectId);
        self::assertNull($message->experiment);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
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
