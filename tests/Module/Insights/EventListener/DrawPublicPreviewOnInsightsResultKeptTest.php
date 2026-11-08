<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\EventListener;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Event\InsightsResultKept;
use App\Module\Insights\Messenger\DrawPublicPreview;
use App\Tests\Module\Insights\PublicPreviewScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DrawPublicPreviewOnInsightsResultKeptTest extends KernelTestCase
{
    use PublicPreviewScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_kept_result_of_a_graph_with_a_visible_link_asks_for_a_draw(): void
    {
        $link = $this->sharedCostGraph('listener-visible', withData: false);
        $this->transport()->reset();

        $this->keep($link);

        self::assertSame([$link->code], $this->askedCodes());
    }

    public function test_a_switched_off_link_a_removed_graph_and_a_taken_down_project_ask_for_nothing(): void
    {
        $off = $this->sharedCostGraph('listener-off', withData: false);
        $off->enabled = false;
        $removed = $this->sharedCostGraph('listener-removed', withData: false);
        $removedGraph = $removed->savedGraph ?? throw new \LogicException('A graph link has a graph.');
        $removedGraph->removedAt = new \DateTimeImmutable('-1 hour');
        $takenDown = $this->sharedCostGraph('listener-takedown', withData: false);
        $this->seedTakedown($takenDown->project);
        $this->em()->flush();
        $this->transport()->reset();

        foreach ([$off, $removed, $takenDown] as $link) {
            $this->keep($link);
        }

        self::assertSame([], $this->askedCodes());
    }

    public function test_a_kept_result_of_an_experiment_with_a_visible_link_asks_for_a_draw(): void
    {
        $project = $this->scenarioProject('listener-experiment');
        $link = PublicLink::forExperiment($project, 'trial');
        $this->em()->persist($link);
        $this->em()->flush();
        $this->transport()->reset();

        $this->dispatch(new InsightsResultKept((string) $project->id, null, 'trial'));

        self::assertSame([$link->code], $this->askedCodes());
    }

    public function test_a_switched_off_experiment_link_and_an_experiment_with_no_link_ask_for_nothing(): void
    {
        $project = $this->scenarioProject('listener-experiment-off');
        $off = PublicLink::forExperiment($project, 'trial');
        $off->enabled = false;
        $this->em()->persist($off);
        $this->em()->flush();
        $this->transport()->reset();

        $this->dispatch(new InsightsResultKept((string) $project->id, null, 'trial'));
        $this->dispatch(new InsightsResultKept((string) $project->id, null, 'no-link'));

        self::assertSame([], $this->askedCodes());
    }

    public function test_a_graph_with_no_link_asks_for_nothing(): void
    {
        $project = $this->scenarioProject('listener-none');
        $graph = $this->seedSavedGraph($project);
        $this->transport()->reset();

        $this->dispatch(new InsightsResultKept((string) $project->id, (string) $graph->id, null));

        self::assertSame([], $this->askedCodes());
    }

    private function keep(PublicLink $link): void
    {
        $this->dispatch(new InsightsResultKept((string) $link->project->id, (string) $link->savedGraph?->id, null));
    }

    private function dispatch(InsightsResultKept $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    /** @return list<string> */
    private function askedCodes(): array
    {
        $codes = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope instanceof Envelope ? $envelope->getMessage() : null;
            if ($message instanceof DrawPublicPreview) {
                $codes[] = $message->code;
            }
        }

        return $codes;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
