<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Inbox\Command\ChangeInboxDecisionCommand;
use App\Module\Inbox\Command\ChangeInboxDecisionHandler;
use App\Module\Inbox\Command\CreateDecisionFollowUpCommand;
use App\Module\Inbox\Command\CreateDecisionFollowUpHandler;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Security\InboxDecisionVoter;
use App\Module\Inbox\Service\InboxDecisionLog;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Tests\Module\Inbox\DecisionFixtures;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ChangeInboxDecisionHandlerTest extends KernelTestCase
{
    use DecisionFixtures;
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->project($em, $this->owner($em, 'decision-change'), 'decision-change');
        $this->card = $this->card($em, $this->project);
        $em->flush();
    }

    public function test_a_change_stores_the_choice_the_note_and_the_person(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();

        $view = $this->change($decision, 'alpha', '  Names in this module use alpha.  ');

        $this->em->clear();
        $stored = $this->em->find(InboxDecision::class, $decision->id);
        self::assertInstanceOf(InboxDecision::class, $stored);
        self::assertSame('alpha', $stored->changedChoice);
        self::assertSame('Names in this module use alpha.', $stored->changedNote);
        self::assertNotNull($stored->changedAt);
        self::assertSame($this->project->owner->id?->toRfc4122(), $stored->changedBy?->id?->toRfc4122());
        self::assertNull($stored->addressedAt);
        self::assertSame('beta', $stored->choice, 'The original choice stays.');
        self::assertSame(InboxDecisionMadeBy::Changed, $stored->madeBy);
        self::assertFalse($view->offersFollowUp, 'The card is not finished.');
        $event = $this->audit->record('inbox.decision_changed');
        self::assertSame((string) $decision->id, $event->context['decisionId'] ?? null);
        self::assertTrue($event->context['hasNote'] ?? null);
    }

    public function test_the_owner_may_write_a_choice_of_their_own(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();

        $this->change($decision, 'Call it gamma', null);

        self::assertSame('Call it gamma', $decision->changedChoice);
        self::assertNull($decision->changedNote);
    }

    public function test_a_second_change_opens_the_decision_again_after_a_worker_addressed_the_first(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();
        $this->change($decision, 'alpha', null);
        $decision->addressedAt = new \DateTimeImmutable();
        $decision->addressedRunId = \Symfony\Component\Uid\Uuid::v4();
        $this->em->flush();

        $this->change($decision, 'gamma', 'Again.');

        $this->em->clear();
        $stored = $this->em->find(InboxDecision::class, $decision->id);
        self::assertTrue($stored?->hasOpenChange());
        self::assertNull($stored->addressedRunId);
        self::assertSame('gamma', $stored->changedChoice);
    }

    public function test_a_change_on_a_finished_card_offers_a_follow_up(): void
    {
        $this->card->column = $this->column($this->project, 'done');
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();

        self::assertTrue($this->change($decision, 'alpha', null)->offersFollowUp);
    }

    public function test_a_defaulted_decision_is_reopened_by_the_same_change(): void
    {
        $item = $this->item($this->em, $this->project, 1);
        $decision = $this->decision($this->em, $this->project, $this->card, 'done', InboxDecisionMadeBy::Defaulted);
        $decision->item = $item;
        $this->em->flush();

        $this->change($decision, 'next', null);

        self::assertSame('next', $decision->changedChoice);
        self::assertSame($item, $decision->item, 'The row keeps the item it answered.');
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function refusals(): iterable
    {
        yield 'a blank choice' => ['   ', null, ChangeInboxDecisionHandler::CHOICE_BLANK];
        yield 'the choice that stands' => ['beta', null, ChangeInboxDecisionHandler::CHOICE_UNCHANGED];
        yield 'a long choice' => [str_repeat('a', 501), null, ChangeInboxDecisionHandler::CHOICE_TOO_LONG];
        yield 'a long note' => ['alpha', str_repeat('a', 2001), ChangeInboxDecisionHandler::NOTE_TOO_LONG];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_change_that_breaks_a_rule_is_refused_and_stores_nothing(string $choice, ?string $note, string $key): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();

        try {
            $this->change($decision, $choice, $note);
            self::fail('The change should be refused.');
        } catch (DomainErrors $e) {
            self::assertContains($key, $e->errors);
        }
        self::assertNull($decision->changedAt);
    }

    public function test_only_the_owner_of_the_project_may_change_a_decision(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $stranger = $this->owner($this->em, 'decision-stranger');
        $this->em->flush();
        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class, $tokens);

        $tokens->setToken(new UsernamePasswordToken($stranger, 'main', $stranger->getRoles()));
        self::assertFalse($checker->isGranted(InboxDecisionVoter::CHANGE, $decision));

        $tokens->setToken(new UsernamePasswordToken($this->project->owner, 'main', $this->project->owner->getRoles()));
        self::assertTrue($checker->isGranted(InboxDecisionVoter::CHANGE, $decision));
    }

    public function test_the_follow_up_card_links_to_the_finished_card(): void
    {
        $this->card->column = $this->column($this->project, 'done');
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();
        $this->change($decision, 'alpha', null);

        $handler = $this->followUpHandler();
        $followUp = $handler(new CreateDecisionFollowUpCommand($decision, 'Rename beta to alpha', 'The owner changed a decision.', 'feature'));

        $this->em->clear();
        $kinds = $this->em->getConnection()->fetchFirstColumn(
            'SELECT kind FROM board_card_links WHERE source_card_id = :source AND target_card_id = :target',
            ['source' => $this->card->id?->toRfc4122(), 'target' => $followUp->id?->toRfc4122()],
        );
        self::assertSame([CardLinkKind::HasFollowUp->value], $kinds, 'The finished card has the new card as its follow-up.');
    }

    public function test_no_follow_up_card_exists_for_a_decision_nobody_changed(): void
    {
        $decision = $this->decision($this->em, $this->project, $this->card);
        $this->em->flush();
        $handler = $this->followUpHandler();

        $this->expectException(DomainErrors::class);
        $handler(new CreateDecisionFollowUpCommand($decision, 'Rename', 'Body', 'feature'));
    }

    private function followUpHandler(): CreateDecisionFollowUpHandler
    {
        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);

        return new CreateDecisionFollowUpHandler($createCard);
    }

    private function change(InboxDecision $decision, string $choice, ?string $note): \App\Module\Inbox\Command\ChangeInboxDecisionView
    {
        $container = self::getContainer();
        $decisionLog = $container->get(InboxDecisionLog::class);
        self::assertInstanceOf(InboxDecisionLog::class, $decisionLog);
        $evaluations = $container->get(CardEvaluations::class);
        self::assertInstanceOf(CardEvaluations::class, $evaluations);
        $handler = new ChangeInboxDecisionHandler($this->em, $decisionLog, $evaluations, $this->audit->auditor);

        return $handler(new ChangeInboxDecisionCommand($decision, $this->project->owner, $choice, $note));
    }
}
