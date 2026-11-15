<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\LiveUpdateTransactionBuffer;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\EventListener\PublishAutomationChangedOnBoardAutomationSettingsSaved;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class PublishAutomationChangedOnBoardAutomationSettingsSavedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', $this->recordingHub());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $owner = new User(fullName: 'Riley', email: 'automation-live-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'automation-live-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_save_sends_one_signal_on_the_board_topic_with_no_settings(): void
    {
        $handler = self::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $handler);

        $handler(new SaveBoardAutomationSettingsCommand(project: $this->project, enabled: false, stuckDelayMinutes: 30));

        // Guard: the publish waits for the end of the request, so nothing went out yet.
        self::assertSame([], $this->published);
        $this->terminate();

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $signals = array_values(array_filter(
            $this->published,
            static fn (Update $update): bool => str_contains($update->getData(), 'board.automation_changed'),
        ));
        self::assertCount(1, $signals);
        self::assertSame([$topics->forBoard($this->project->id ?? throw new \LogicException('Project has no id.'))], $signals[0]->getTopics());
        self::assertTrue($signals[0]->isPrivate());
        self::assertSame('{"type":"board.automation_changed","origin":null}', $signals[0]->getData());
    }

    public function test_with_live_updates_off_it_publishes_nothing(): void
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $publisher = new LiveUpdatePublisher(
            new RequestStack(),
            new LiveUpdateTransactionBuffer(),
            FeatureFlags::service([LiveUpdates::FLAG => false]),
            new NullLogger(),
            static fn (): HubInterface => throw new \LogicException('the hub must not be built with live updates off'),
        );

        (new PublishAutomationChangedOnBoardAutomationSettingsSaved($topics, $publisher))(new BoardAutomationSettingsSaved($this->project));
        $publisher->publish();

        self::assertSame([], $this->published);
    }

    private function terminate(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
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
