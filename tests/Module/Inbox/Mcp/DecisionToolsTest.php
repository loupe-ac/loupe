<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Mcp\DecisionListTool;
use App\Module\Inbox\Mcp\DecisionMarkAddressedTool;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\DecisionFixtures;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DecisionToolsTest extends KernelTestCase
{
    use DecisionFixtures;
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->enableInbox();
        $this->project = $this->makeProject('decision-tools');
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_the_list_gives_the_open_changes_of_the_card_only(): void
    {
        $card = $this->card($this->em, $this->project, 1);
        $other = $this->card($this->em, $this->project, 2);
        $open = $this->changed($this->decision($this->em, $this->project, $card), 'alpha', 'Use alpha.');
        $this->decision($this->em, $this->project, $card, 'gamma');
        $addressed = $this->changed($this->decision($this->em, $this->project, $card, 'delta'), 'alpha', null);
        $addressed->addressedAt = new \DateTimeImmutable();
        $this->changed($this->decision($this->em, $this->project, $other), 'alpha', null);
        $this->em->flush();

        $result = $this->list()((string) $card->id);

        self::assertCount(1, $result['decisions']);
        $row = $result['decisions'][0];
        self::assertSame((string) $open->id, $row['id']);
        self::assertSame(['naming', 'beta', 'alpha', 'Use alpha.'], [$row['label'], $row['originalChoice'], $row['changedChoice'], $row['note']]);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['changedAt']));
    }

    public function test_the_list_never_reads_a_card_of_another_project(): void
    {
        $foreign = $this->makeProject('decision-tools-foreign');
        $card = $this->card($this->em, $foreign, 1);
        $this->changed($this->decision($this->em, $foreign, $card), 'alpha', null);
        $this->em->flush();

        self::assertSame([], $this->list()((string) $card->id)['decisions']);
    }

    public function test_marking_stores_the_time_and_the_run_of_the_session(): void
    {
        $card = $this->card($this->em, $this->project);
        $decision = $this->changed($this->decision($this->em, $this->project, $card), 'alpha', null);
        $sessionId = Uuid::v4();
        $run = $this->workerRun($card, $sessionId);
        $this->em->flush();

        $result = $this->mark()([(string) $decision->id], (string) $sessionId);

        self::assertSame([(string) $decision->id], $result['addressed']);
        $this->em->clear();
        $stored = $this->em->find(InboxDecision::class, $decision->id);
        self::assertNotNull($stored?->addressedAt);
        self::assertSame((string) $run->id, (string) $stored->addressedRunId);
        self::assertSame([], $this->list()((string) $card->id)['decisions']);
    }

    public function test_a_change_that_is_addressed_already_keeps_its_first_mark(): void
    {
        $card = $this->card($this->em, $this->project);
        $decision = $this->changed($this->decision($this->em, $this->project, $card), 'alpha', null);
        $first = new \DateTimeImmutable('2026-01-01 10:00:00');
        $decision->addressedAt = $first;
        $this->em->flush();

        self::assertSame([], $this->mark()([(string) $decision->id], (string) Uuid::v4())['addressed']);

        $this->em->clear();
        self::assertEquals($first, $this->em->find(InboxDecision::class, $decision->id)?->addressedAt);
    }

    public function test_a_decision_of_another_project_is_refused_and_nothing_is_marked(): void
    {
        $card = $this->card($this->em, $this->project, 1);
        $own = $this->changed($this->decision($this->em, $this->project, $card), 'alpha', null);
        $foreign = $this->makeProject('decision-tools-other');
        $foreignDecision = $this->changed($this->decision($this->em, $foreign, $this->card($this->em, $foreign, 1)), 'alpha', null);
        $this->em->flush();

        try {
            $this->mark()([(string) $own->id, (string) $foreignDecision->id], (string) Uuid::v4());
            self::fail('The call should be refused.');
        } catch (ToolCallException $e) {
            self::assertSame('decisionIds: A decision id names no decision of this project. Pass the ids that decision_list gave you.', $e->getMessage());
        }
        $this->em->clear();
        self::assertNull($this->em->find(InboxDecision::class, $own->id)?->addressedAt);
        self::assertNull($this->em->find(InboxDecision::class, $foreignDecision->id)?->addressedAt);
    }

    public function test_a_decision_with_no_change_is_refused(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card($this->em, $this->project));
        $this->em->flush();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('decisionIds: ');
        $this->mark()([(string) $decision->id], (string) Uuid::v4());
    }

    public function test_a_worker_of_another_card_cannot_mark_the_decision(): void
    {
        $card = $this->card($this->em, $this->project, 1);
        $decision = $this->changed($this->decision($this->em, $this->project, $card), 'alpha', null);
        $sessionId = Uuid::v4();
        $this->workerRun($this->card($this->em, $this->project, 2), $sessionId);
        $this->em->flush();

        try {
            $this->mark()([(string) $decision->id], (string) $sessionId);
            self::fail('The call should be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('another card', $e->getMessage());
        }
        $this->em->clear();
        self::assertNull($this->em->find(InboxDecision::class, $decision->id)?->addressedAt);
    }

    public function test_both_tools_refuse_while_the_inbox_is_off(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        foreach ([fn () => $this->list()((string) Uuid::v4()), fn () => $this->mark()([(string) Uuid::v4()], (string) Uuid::v4())] as $call) {
            try {
                $call();
                self::fail('The call should be refused.');
            } catch (ToolCallException $e) {
                self::assertSame('The inbox is switched off on this instance.', $e->getMessage());
            }
        }
    }

    private function changed(InboxDecision $decision, string $choice, ?string $note): InboxDecision
    {
        $decision->changedChoice = $choice;
        $decision->changedNote = $note;
        $decision->changedAt = new \DateTimeImmutable('-1 minute');

        return $decision;
    }

    private function workerRun(\App\Module\Board\Entity\Card $card, Uuid $sessionId): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'fix',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
            output: '',
        );
        $this->em->persist($run);

        return $run;
    }

    private function list(): DecisionListTool
    {
        $tool = self::getContainer()->get(DecisionListTool::class);
        self::assertInstanceOf(DecisionListTool::class, $tool);

        return $tool;
    }

    private function mark(): DecisionMarkAddressedTool
    {
        $tool = self::getContainer()->get(DecisionMarkAddressedTool::class);
        self::assertInstanceOf(DecisionMarkAddressedTool::class, $tool);

        return $tool;
    }
}
