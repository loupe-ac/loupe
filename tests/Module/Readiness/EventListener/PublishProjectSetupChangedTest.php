<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Forge\Event\ForgeRepositoryJoined;
use App\Module\Forge\Event\ForgeRepositoryLeft;
use App\Module\Project\Contract\Event\ProjectMilestoneReached;
use App\Module\Project\Contract\Event\ProjectSetUp;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\NotSetUp;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class PublishProjectSetupChangedTest extends KernelTestCase
{
    use ReadinessScenario;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', $this->recordingHub());
    }

    public function test_each_fact_the_page_lists_publishes_one_event_on_the_board_topic(): void
    {
        $project = $this->gated('setup-publish-facts@example.com');
        $projectId = $project->requireId();

        foreach ([
            new ForgeRepositoryJoined($projectId),
            new ForgeRepositoryLeft($projectId),
            new ProjectMilestoneReached($projectId, 'init-finished'),
            new ProjectSetUp($projectId),
            new WorkerRunChanged($projectId, [], []),
        ] as $event) {
            $this->published = [];
            $this->dispatcher()->dispatch($event);
            $this->terminate();

            self::assertCount(1, $this->published, $event::class);
            $topic = self::getContainer()->get(ProjectTopicBuilder::class)->forBoard($projectId);
            self::assertSame([$topic], $this->published[0]->getTopics());
            self::assertTrue($this->published[0]->isPrivate());
            self::assertSame(['type' => 'project.setup_changed', 'projectId' => (string) $projectId, 'origin' => null], json_decode($this->published[0]->getData(), true));
        }
    }

    public function test_a_run_change_of_a_project_that_is_set_up_publishes_nothing(): void
    {
        $owner = $this->user('setup-publish-done@example.com');
        $project = $this->project($owner, 'Set up already');
        $project->setUp = true;
        $this->em()->flush();

        $this->dispatcher()->dispatch(new WorkerRunChanged($project->requireId(), [], []));
        $this->terminate();

        self::assertSame([], $this->published);
    }

    public function test_a_run_change_of_a_project_that_is_gone_publishes_nothing(): void
    {
        $this->dispatcher()->dispatch(new WorkerRunChanged(\Symfony\Component\Uid\Uuid::v7(), [], []));
        $this->terminate();

        self::assertSame([], $this->published);
    }

    /** @param non-empty-string $email */
    private function gated(string $email): Project
    {
        $project = $this->project($this->user($email), 'Publish setup');
        NotSetUp::mark($this->em(), $project);

        return $project;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function terminate(): void
    {
        self::assertNotNull(self::$kernel);
        $this->dispatcher()->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    private function recordingHub(): HubInterface
    {
        return new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        );
    }
}
