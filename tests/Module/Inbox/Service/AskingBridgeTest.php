<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AskingBridgeTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private AskingBridge $askingBridge;
    private WorkerRunRepository $workerRuns;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $askingBridge = self::getContainer()->get(AskingBridge::class);
        self::assertInstanceOf(AskingBridge::class, $askingBridge);
        $this->askingBridge = $askingBridge;
        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);
        $this->workerRuns = $workerRuns;
        $this->project = $this->project($em, $this->owner($em, 'asking-bridge'), 'asking-bridge');
        $em->flush();
    }

    public function test_the_bridge_the_call_names_is_found(): void
    {
        $bridge = $this->bridge($this->project->owner, [(string) $this->project->id]);

        self::assertSame($bridge, $this->askingBridge->find($this->project, Uuid::v4(), $bridge->id));
    }

    public function test_the_bridge_of_the_open_ask_of_the_session_is_the_next_choice(): void
    {
        $bridge = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $sessionId = Uuid::v4();
        $this->em->persist(new InboxAsk(project: $this->project, sessionId: $sessionId, bridgeId: $bridge->id));
        $this->em->flush();

        self::assertSame($bridge, $this->askingBridge->find($this->project, $sessionId, null));
    }

    public function test_the_bridge_of_the_first_run_of_the_session_is_the_last_choice(): void
    {
        $bridge = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $sessionId = Uuid::v4();
        $this->startRun($bridge->id, $sessionId, '-10 minutes');
        $this->startRun(Uuid::v4(), $sessionId, '-1 minute');

        self::assertSame($bridge, $this->askingBridge->find($this->project, $sessionId, null));
    }

    public function test_a_session_with_no_bridge_has_none(): void
    {
        self::assertNull($this->askingBridge->find($this->project, Uuid::v4(), null));
    }

    public function test_a_bridge_of_another_owner_is_not_found(): void
    {
        $other = $this->owner($this->em, 'asking-bridge-other');
        $foreign = $this->bridge($other, [(string) $this->project->id]);

        self::assertNull($this->askingBridge->find($this->project, Uuid::v4(), $foreign->id));
    }

    public function test_a_bridge_that_does_not_follow_the_project_is_not_found(): void
    {
        $bridge = $this->bridge($this->project->owner, []);

        self::assertNull($this->askingBridge->find($this->project, Uuid::v4(), $bridge->id));
    }

    public function test_the_bridge_of_the_first_ask_that_names_one_is_the_bridge_of_a_todo(): void
    {
        $first = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $second = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $item = $this->todoHeldBy(
            new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: null, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00')),
            new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $first->id, createdAt: new \DateTimeImmutable('2026-10-01 11:00:00')),
            new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $second->id, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00')),
        );

        self::assertSame($first, $this->askingBridge->findForItem($item));
    }

    public function test_a_todo_whose_asks_name_no_bridge_takes_the_bridge_of_the_first_run_of_the_session(): void
    {
        $bridge = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $sessionId = Uuid::v4();
        $this->startRun($bridge->id, $sessionId, '-10 minutes');
        $this->startRun(Uuid::v4(), $sessionId, '-1 minute');
        $item = $this->todoHeldBy(new InboxAsk(project: $this->project, sessionId: $sessionId, bridgeId: null));

        self::assertSame($bridge, $this->askingBridge->findForItem($item));
    }

    public function test_a_todo_with_no_ask_bridge_and_no_run_has_none(): void
    {
        self::assertNull($this->askingBridge->findForItem($this->todoHeldBy(new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: null))));
    }

    public function test_the_bridge_of_a_todo_must_follow_the_project(): void
    {
        $bridge = $this->bridge($this->project->owner, []);

        self::assertNull($this->askingBridge->findForItem($this->todoHeldBy(new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $bridge->id))));
    }

    public function test_the_bridge_of_a_run_is_found_when_it_follows_the_project(): void
    {
        $bridge = $this->bridge($this->project->owner, [(string) $this->project->id]);
        $sessionId = Uuid::v4();
        $this->startRun($bridge->id, $sessionId, '-1 minute');
        $run = $this->workerRuns->findOneBy(['sessionId' => $sessionId]);
        self::assertInstanceOf(WorkerRun::class, $run);

        self::assertSame($bridge, $this->askingBridge->findForRun($run));

        $bridge->projects = [];
        $this->em->flush();

        self::assertNull($this->askingBridge->findForRun($run));
    }

    private function todoHeldBy(InboxAsk ...$asks): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: random_int(1, 100000), kind: InboxItemKind::Todo, title: 'Export it', blocking: false);
        $this->em->persist($item);
        foreach ($asks as $ask) {
            $this->em->persist($ask);
            $this->em->persist(new InboxAskItem($ask, $item));
        }
        $this->em->flush();

        return $item;
    }

    /** @param list<string> $projects */
    private function bridge(User $owner, array $projects): Bridge
    {
        $bridge = new Bridge($owner, Uuid::v4(), $projects, 'b4e39aa7', new \DateTimeImmutable());
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    private function startRun(Uuid $bridgeId, Uuid $sessionId, string $startedAt): void
    {
        $card = $this->card($this->em, $this->project, random_int(1, 100000));
        $this->em->persist(new WorkerRun(
            project: $this->project,
            bridgeId: $bridgeId,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('The card has no id.'),
            cardNumber: $card->number,
            workKind: 'plan',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
            startedAt: new \DateTimeImmutable($startedAt),
        ));
        $this->em->flush();
    }
}
