<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a card in the Implementation column of the Lifecycle project whose pull request waits for the answer to the
 * merge question. The marker is stored as the engine stores it, with an empty inbox set and no due time, so the minute
 * task leaves it alone. The dev seed keeps the inbox off, so the card shows the link that replaces the inbox link.
 */
#[When('dev')]
#[When('test')]
final readonly class DevMergeQuestionMarkerSeeder implements DevSeederInterface
{
    public const string KEY = 'board-merge-question-marker';

    private const int AGE = 330;

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
    ) {
    }

    public static function cardId(): Uuid
    {
        return DevSeedId::of('board/card/merge-question-marker');
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
        $card = $this->cards->find(self::cardId());
        if (!$card instanceof Card) {
            $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
                ?? throw new \LogicException('The lifecycle project does not exist.');
            $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'implementation' === $column->slug)
                ?? throw new \LogicException('The lifecycle project has no implementation column.');
            $card = new Card(
                project: $project,
                column: $column,
                title: 'A card whose pull request waits for the merge question',
                body: 'Shows the marker of a merge that waits for the answer to the merge question.',
                number: $this->cards->nextNumber($project),
                type: 'feature',
                createdAt: $context->at(self::AGE),
            );
            $context->ids->assign($card, self::cardId());
            $this->em->persist($card);
        }

        $card->markers = [[
            'key' => 'waits-for-merge-approval-answer',
            'kind' => MarkerKind::NeedsYou->value,
            'label' => 'marker.waits_for_merge_approval_answer',
            'remedy' => 'marker.waits_for_merge_approval_answer.remedy',
            'params' => ['%number%' => 42],
            'since' => $context->at(self::AGE - 10)->format(\DATE_ATOM),
            'link' => MarkerLink::Inbox->value,
        ]];
        $card->inboxMarkers = [];
        $card->markersDueAt = null;
        $this->em->flush();
    }
}
