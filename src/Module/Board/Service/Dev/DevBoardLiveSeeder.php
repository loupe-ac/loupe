<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes more Backlog cards and more finished cards than one page holds into the live board project, so
 * the second page of each list has rows. Its twin project has the same columns and no card.
 */
#[When('dev')]
#[When('test')]
final readonly class DevBoardLiveSeeder implements DevSeederInterface
{
    public const string KEY = 'board-live';

    /** One more than the rows of a page, so each list has a second page. */
    public const int CARDS_PER_LIST = ListBacklogCardsHandler::PER_PAGE + 1;

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
    ) {
    }

    public static function cardId(string $list, int $index): Uuid
    {
        return DevSeedId::of('board/live/'.$list.'/'.$index);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::liveBoardProjectId())
            ?? throw new \LogicException('The live board project does not exist.');
        $columns = $this->boardColumns->findForProject($project);
        $backlog = array_find($columns, static fn (BoardColumn $column): bool => 'backlog' === $column->slug)
            ?? throw new \LogicException('The live board project has no backlog column.');
        $done = array_find($columns, static fn (BoardColumn $column): bool => 'done' === $column->slug)
            ?? throw new \LogicException('The live board project has no done column.');

        foreach (['backlog' => $backlog, 'done' => $done] as $list => $column) {
            for ($index = 1; $index <= self::CARDS_PER_LIST; ++$index) {
                if ($this->cards->find(self::cardId($list, $index)) instanceof Card) {
                    continue;
                }

                $at = $context->at(86_400 + $index * 60);
                $card = new Card(
                    project: $project,
                    column: $column,
                    title: \sprintf('%s card %d', 'backlog' === $list ? 'Backlog' : 'Finished', $index),
                    body: 'A card that fills the pages of a live list.',
                    number: $this->cards->nextNumber($project),
                    type: 'feature',
                    createdAt: $at,
                );
                if ($column->terminal) {
                    $card->complete($at);
                }
                $context->ids->assign($card, self::cardId($list, $index));
                $this->em->persist($card);
                $this->em->flush();
            }
        }
    }
}
