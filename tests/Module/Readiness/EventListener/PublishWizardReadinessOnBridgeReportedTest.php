<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class PublishWizardReadinessOnBridgeReportedTest extends KernelTestCase
{
    use BridgeScenario;

    /** @var list<Update> */
    private array $published = [];

    public function test_a_heartbeat_publishes_when_the_reading_changes_and_stays_silent_when_it_does_not(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));
        $em = $this->em();
        $owner = $this->user($em, 'wizard-heartbeat@example.com');
        $project = $this->project($em, $owner, 'Wizard heartbeat');
        $bridgeId = Uuid::v4();
        $projectId = (string) $project->id;

        $this->heartbeat($this->beat($owner, $bridgeId, $projectId, []));
        self::assertCount(1, $this->readinessUpdates($project), 'the first reading is a change');

        $this->heartbeat($this->beat($owner, $bridgeId, $projectId, []));
        self::assertCount(1, $this->readinessUpdates($project), 'a heartbeat that changes nothing publishes nothing');

        $this->heartbeat($this->beat($owner, $bridgeId, $projectId, [['projectId' => $projectId, 'path' => '/work/loupe', 'remoteUrl' => 'git@github.com:acme/loupe.git']]));
        self::assertCount(2, $this->readinessUpdates($project), 'a reported repository changes the reading');

        $this->heartbeat($this->beat($owner, $bridgeId, $projectId, [['projectId' => $projectId, 'path' => '/work/loupe', 'remoteUrl' => 'git@github.com:acme/loupe.git']]));
        self::assertCount(2, $this->readinessUpdates($project));
    }

    /** @param list<array{projectId: string, path: string, remoteUrl: string}> $repositories */
    private function beat(User $owner, Uuid $bridgeId, string $projectId, array $repositories): RecordBridgeHeartbeatCommand
    {
        return new RecordBridgeHeartbeatCommand($owner, $bridgeId, [$projectId], 'b4e39aa7', repositories: $repositories);
    }

    private function heartbeat(RecordBridgeHeartbeatCommand $command): void
    {
        $handler = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $handler);
        $handler($command);

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
}
