<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
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
