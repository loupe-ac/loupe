<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\CapturesLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublishDiscoveryChangedOnDiscoveryRunSavedTest extends KernelTestCase
{
    use CapturesLiveUpdates;
    use DiscoveryScenario;

    public function test_a_new_run_and_each_state_change_publish_on_the_board_topic(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $project = $this->workflowProject('discovery-live');
        $card = $this->discoveryCard($project);
        $this->drainLiveUpdates();

        $run = $this->discoveryRun($card);
        self::assertSame([$this->message($project)], $this->changed());

        $run->state = DiscoveryRunState::Reported;
        $this->em()->flush();
        self::assertSame([$this->message($project)], $this->changed());
    }

    public function test_a_flush_that_changes_no_run_publishes_nothing(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $project = $this->workflowProject('discovery-quiet');
        $run = $this->discoveryRun($this->discoveryCard($project));
        // Guard: the run reached the hub, so the empty list below follows a working listener.
        self::assertNotSame([], $this->changed());

        $this->em()->flush();

        self::assertSame([], $this->changed());
        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    /** @return array{topic: string, data: array{type: string, projectId: string, origin: null}} */
    private function message(Project $project): array
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $id = $project->id ?? throw new \LogicException('Project has no id.');

        return [
            'topic' => $topics->forBoard($id),
            'data' => ['type' => 'project.discovery_changed', 'projectId' => (string) $id, 'origin' => null],
        ];
    }

    /** @return list<array{topic: string, data: array<string, mixed>}> */
    private function changed(): array
    {
        return array_values(array_filter(
            $this->drainLiveUpdates(),
            static fn (array $update): bool => 'project.discovery_changed' === ($update['data']['type'] ?? null),
        ));
    }
}
