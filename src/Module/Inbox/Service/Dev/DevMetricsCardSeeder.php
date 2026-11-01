<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Gives the demo runs of the metrics seeder a done card each, so the run drawer
 * links to a card that exists. It lives here because only Board, Inbox,
 * Readiness and AgentReview may import Board.
 */
#[When('dev')]
#[When('test')]
final readonly class DevMetricsCardSeeder
{
    public function __construct(
        private EntityManagerInterface $em,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
    ) {
    }

    /**
     * The card of the demo runs from $from to $to, made on the first call.
     *
     * @return array{Uuid, int} card id, card number
     */
    public function cardFor(Project $project, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $title = \sprintf('Demo runs from %s', $from->format('Y-m-d'));
        $card = $this->cards->findOneBy(['project' => $project, 'title' => $title]);
        if (!$card instanceof Card) {
            $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'done' === $column->slug)
                ?? throw new \LogicException('The project has a done column.');
            $card = new Card(project: $project, column: $column, title: $title, body: '', number: $this->cards->nextNumber($project), type: 'feature', createdAt: $from);
            $card->completedAt = $to;
            $this->em->persist($card);
            $this->em->flush();
            $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $from);
            $this->em->flush();
        }

        return [$card->id ?? throw new \LogicException('A stored card has an id.'), $card->number];
    }
}
