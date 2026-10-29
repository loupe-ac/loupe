<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\BoardCardMilestoneSource;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardMilestoneSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private BoardCardMilestoneSource $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(BoardCardMilestoneSource::class);
        self::assertInstanceOf(BoardCardMilestoneSource::class, $source);
        $this->source = $source;
    }

    public function test_it_answers_the_pull_request_times_the_ready_row_and_the_moves_of_each_card(): void
    {
        $project = $this->makeProject('milestones');
        $other = $this->makeProject('milestones-other');
        $review = $this->column($project, 'in-progress');
        $done = $this->column($project, 'done');
        $templates = new TemplateMetricSet(slotColumns: ['review' => $review->id, '@terminal' => $done->id, 'no-column' => null]);
        $card = $this->card($project, 1, 'done');
        $quiet = $this->card($project, 2, 'in-progress');
        $foreign = $this->card($other, 1, 'done');

        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $this->event($card, CardEventKind::Moved, ['from' => ['id' => 'x'], 'to' => CardEvent::columnDetail($review), 'cause' => null], $at('09:00:00'));
        $this->event($card, CardEventKind::ReadyToMerge, ['pullRequest' => 7], $at('11:00:00'));
        $this->event($card, CardEventKind::Moved, ['from' => CardEvent::columnDetail($review), 'to' => CardEvent::columnDetail($done), 'cause' => null], $at('12:00:00'));
        $this->event($card, CardEventKind::FixRequested, ['reason' => 'conflict'], $at('10:00:00'));
        $this->event($foreign, CardEventKind::ReadyToMerge, ['pullRequest' => 7], $at('11:00:00'));
        $this->pullRequest($project, $card, 7, $at('10:00:00'), null);
        $this->pullRequest($project, $card, 8, $at('08:00:00'), $at('12:30:00'));
        $this->pullRequest($other, $foreign, 7, $at('07:00:00'), $at('07:30:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card, $quiet, $foreign, Uuid::v7()], $templates);

        self::assertSame([(string) $card], array_keys($milestones));
        $found = array_map(static fn (CardMilestone $milestone): string => $milestone->event.'|'.($milestone->slot ?? '').'|'.$milestone->at->format('H:i'), $milestones[(string) $card]);
        sort($found);
        $expected = [
            'arrived|review|09:00',
            'arrived|@terminal|12:00',
            'left|review|12:00',
            DurationEvent::PR_MERGED.'||12:30',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::PR_OPENED.'||10:00',
            DurationEvent::READY_TO_MERGE.'||11:00',
        ];
        sort($expected);
        self::assertSame($expected, $found);
        self::assertSame([], $this->source->milestonesOf($project, [], $templates));
    }

    /** @param array<string, mixed> $detail */
    private function event(Uuid $cardId, CardEventKind $kind, array $detail, \DateTimeImmutable $at): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $events->record($card, $kind, Actor::System, null, $detail, $at);
        $this->em->flush();
    }

    private function pullRequest(Project $project, Uuid $cardId, int $number, \DateTimeImmutable $openedAt, ?\DateTimeImmutable $mergedAt): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));
        $row = new ForgePullRequest($project, Forge::GitHub->value, 'Acme/Widgets', $number);
        $row->openedAt = $openedAt;
        $row->mergedAt = $mergedAt;
        $this->em->persist($row);
        $this->em->flush();
    }

    private function card(Project $project, int $number, string $column): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number, type: 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
