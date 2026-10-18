<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Service\WizardReadinessPublisher;
use App\Tests\Module\Bridge\BridgeScenario;
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

final class WizardReadinessPublisherTest extends KernelTestCase
{
    use BridgeScenario;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', $this->recordingHub());
    }

    public function test_the_first_reading_publishes_once_and_an_unchanged_one_publishes_nothing(): void
    {
        $project = $this->newProject('wizard-publisher-first@example.com');
        $publisher = $this->publisher();

        self::assertTrue($publisher->publishIfChanged($project));
        $this->terminate();
        self::assertCount(1, $this->readinessUpdates($project));

        self::assertFalse($publisher->publishIfChanged($project));
        self::assertFalse($publisher->publishIfChanged($project));
        $this->terminate();
        self::assertCount(1, $this->readinessUpdates($project));
    }

    public function test_a_changed_reading_publishes_again_on_the_board_topic_with_the_project_id(): void
    {
        $project = $this->newProject('wizard-publisher-change@example.com');
        $publisher = $this->publisher();
        $publisher->publishIfChanged($project);
        $this->terminate();

        $this->seedBridge($this->em(), $project->owner, projects: [(string) $project->id]);
        self::assertTrue($publisher->publishIfChanged($project));
        $this->terminate();

        $updates = $this->readinessUpdates($project);
        self::assertCount(2, $updates);
        $topic = self::getContainer()->get(ProjectTopicBuilder::class)->forBoard($project->id ?? throw new \LogicException('No id.'));
        self::assertSame([$topic], $updates[1]->getTopics());
        self::assertTrue($updates[1]->isPrivate());
        self::assertSame(['type' => 'bridge.readiness_changed', 'projectId' => (string) $project->id, 'origin' => null], json_decode($updates[1]->getData(), true));
    }

    /** @param non-empty-string $email */
    private function newProject(string $email): Project
    {
        $em = $this->em();

        return $this->project($em, $this->user($em, $email), 'Wizard '.$email);
    }

    private function publisher(): WizardReadinessPublisher
    {
        $publisher = self::getContainer()->get(WizardReadinessPublisher::class);
        self::assertInstanceOf(WizardReadinessPublisher::class, $publisher);

        return $publisher;
    }

    private function terminate(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    /** @return list<Update> */
    private function readinessUpdates(Project $project): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (Update $update): bool => str_contains($update->getData(), 'bridge.readiness_changed') && str_contains($update->getData(), (string) $project->id),
        ));
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
