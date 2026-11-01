<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Insights\Service\Dev\DevAnalysesSeeder;
use App\Module\Insights\Service\Dev\DevMetricsSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the demo runs and gives them a done card per span, so the run drawer
 * links to a card that exists. It lives here because only Board, Inbox,
 * Readiness and AgentReview may import Board. The metrics seeder carries no
 * tag, because Insights may not import Board.
 */
#[When('dev')]
#[When('test')]
final readonly class DevMetricsCardSeeder implements DevSeederInterface
{
    public const string KEY = 'metrics-cards';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DevMetricsSeeder $metrics,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevAnalysesSeeder::KEY];
    }

    /** The bucket rules of the analyses sort the tool time of the runs, so the analyses go first. */
    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');

        $this->metrics->seed($context, $project, fn (\DateTimeImmutable $from, \DateTimeImmutable $to): array => $this->cardFor($context, $project, $from, $to));
    }

    /**
     * The card of the demo runs from $from to $to, made on the first call.
     *
     * @return array{Uuid, int} card id, card number
     */
    public function cardFor(SeedContext $context, Project $project, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $id = DevSeedId::of('board/card/demo-runs-'.$from->format('Y-m-d'));
        $card = $this->cards->find($id);
        if (!$card instanceof Card) {
            $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'done' === $column->slug)
                ?? throw new \LogicException('The project has a done column.');
            $card = new Card(project: $project, column: $column, title: \sprintf('Demo runs from %s', $from->format('Y-m-d')), body: '', number: $this->cards->nextNumber($project), type: 'feature', createdAt: $from);
            $card->completedAt = $to;
            $context->ids->assign($card, $id);
            $this->em->persist($card);
            $this->em->flush();
            $this->cardEvents->record($card, CardEventKind::Created, Actor::Agent, null, [], $from);
            $this->em->flush();
        }

        return [$card->id ?? throw new \LogicException('A stored card has an id.'), $card->number];
    }
}
