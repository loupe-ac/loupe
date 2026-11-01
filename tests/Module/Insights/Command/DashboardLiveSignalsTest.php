<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Insights\Command\MoveSavedGraphCommand;
use App\Module\Insights\Command\MoveSavedGraphHandler;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\RenameSavedGraphCommand;
use App\Module\Insights\Command\RenameSavedGraphHandler;
use App\Module\Insights\Command\ResizeSavedGraphCommand;
use App\Module\Insights\Command\ResizeSavedGraphHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Command\SetLegendSavedGraphCommand;
use App\Module\Insights\Command\SetLegendSavedGraphHandler;
use App\Module\Insights\Command\UpdateSavedGraphCommand;
use App\Module\Insights\Command\UpdateSavedGraphHandler;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/** Each graph handler queues one signal after its flush, and the signal leaves at the end of the request. */
final class DashboardLiveSignalsTest extends KernelTestCase
{
    use InsightsScenario;

    private const array SETTINGS = ['metric' => 'cost', 'unit' => 'run', 'statistic' => 'sum', 'range' => 'thirty-days', 'bucket' => 'day'];

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));
    }

    public function test_a_save_and_an_update_each_send_one_signal_after_the_request(): void
    {
        $project = $this->scenarioProject('signal-save');

        $saved = $this->service(SaveSavedGraphHandler::class)(new SaveSavedGraphCommand($project, 'One', self::SETTINGS));
        $this->assertSignals($project, [], 'a signal waits for the end of the request');
        $this->terminate();
        $this->assertSignals($project, [['saved', (string) $saved->graph->id]]);

        $this->service(UpdateSavedGraphHandler::class)(new UpdateSavedGraphCommand($project, (string) $saved->graph->id, null, self::SETTINGS));
        $this->terminate();
        $this->assertSignals($project, [['saved', (string) $saved->graph->id], ['updated', (string) $saved->graph->id]]);
    }

    public function test_each_tile_action_sends_one_signal_with_its_kind(): void
    {
        $project = $this->scenarioProject('signal-tile');
        $first = $this->seedSavedGraph($project, 'A', 0);
        $second = $this->seedSavedGraph($project, 'B', 1);
        $id = (string) $second->id;

        $this->service(RenameSavedGraphHandler::class)(new RenameSavedGraphCommand($second, 'Renamed'));
        $this->service(ResizeSavedGraphHandler::class)(new ResizeSavedGraphCommand($second, SavedGraphWidth::Full));
        $this->service(SetLegendSavedGraphHandler::class)(new SetLegendSavedGraphCommand($second, false));
        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($second, -1));
        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($second));
        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($second));
        $this->terminate();

        $this->assertSignals($project, [
            ['renamed', $id], ['resized', $id], ['legend-changed', $id], ['moved', $id], ['removed', $id], ['restored', $id],
        ]);
        self::assertNotSame('', (string) $first->id);
    }

    public function test_a_change_that_changes_nothing_or_is_refused_sends_no_signal(): void
    {
        $project = $this->scenarioProject('signal-none');
        $only = $this->seedSavedGraph($project, 'A', 0);

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($only, -1));
        $this->service(RestoreSavedGraphHandler::class)(new RestoreSavedGraphCommand($only));
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_a_save_refused_at_the_cap_sends_no_signal(): void
    {
        $project = $this->scenarioProject('signal-cap');
        for ($i = 0; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }

        try {
            $this->service(SaveSavedGraphHandler::class)(new SaveSavedGraphCommand($project, 'Too many', self::SETTINGS));
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_the_signal_names_no_graph_name_and_no_number(): void
    {
        $project = $this->scenarioProject('signal-payload');
        $graph = $this->seedSavedGraph($project, 'Secret name', 0);

        $this->service(RenameSavedGraphHandler::class)(new RenameSavedGraphCommand($graph, 'Another secret'));
        $this->terminate();

        $update = $this->updatesOf($project)[0] ?? throw new \LogicException('A signal was sent.');
        self::assertSame(['type', 'graphId', 'kind', 'origin'], array_keys(json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('secret', strtolower($update->getData()));
        self::assertTrue($update->isPrivate());
    }

    /** @param list<array{string, string}> $expected the kind and the graph id of each signal, in order */
    private function assertSignals(Project $project, array $expected, string $message = ''): void
    {
        $actual = array_map(static function (Update $update): array {
            $data = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);

            return [$data['kind'], $data['graphId']];
        }, $this->updatesOf($project));
        self::assertSame($expected, $actual, $message);
    }

    /** @return list<Update> */
    private function updatesOf(Project $project): array
    {
        $topic = $this->service(ProjectTopicBuilder::class)->forDashboard($project->requireId());

        return array_values(array_filter($this->published, static fn (Update $update): bool => \in_array($topic, $update->getTopics(), true)));
    }

    private function terminate(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
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
