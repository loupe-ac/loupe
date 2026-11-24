<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Writes the decisions of two dev cards, so the Decisions tab shows a defaulted, a logged and a changed
 * entry. A re-seed restores an entry that is missing and leaves the others as they are.
 */
#[When('dev')]
#[When('test')]
final readonly class DevInboxDecisionSeeder implements DevSeederInterface
{
    public const string KEY = 'inbox-decisions';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private CardRepository $cards,
        private InboxDecisionRepository $inboxDecisions,
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
        return [DevBoardSeeder::KEY, DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $checkout = $this->cards->find(DevBoardSeeder::cardId('checkout'))
            ?? throw new \LogicException('The card checkout does not exist.');
        $history = $this->cards->find(DevBoardSeeder::cardId('history'))
            ?? throw new \LogicException('The card history does not exist.');
        $now = new \DateTimeImmutable();

        $entries = [
            'defaulted' => new InboxDecision($project, $checkout, 'test-choice', 'Cache the column counts for 30 s', ['Cache for 5 min'], InboxDecisionMadeBy::Defaulted, $now->modify('-3 hours')),
            'logged' => new InboxDecision($project, $checkout, 'naming', 'Named the cache key board.columnCounts', ['board.counts', 'columns.cardCount'], InboxDecisionMadeBy::Logged, $now->modify('-2 hours')),
            'changed' => new InboxDecision($project, $checkout, 'ordering', 'Moved the count above the column title', ['Count after the title'], InboxDecisionMadeBy::Logged, $now->modify('-1 hour')),
            'history' => new InboxDecision($project, $history, 'wording', 'Wrote the empty column text as "No cards here yet"', [], InboxDecisionMadeBy::Logged, $now->modify('-30 minutes')),
        ];
        $entries['changed']->madeBy = InboxDecisionMadeBy::Changed;
        $entries['changed']->changedChoice = 'Count after the title';
        $entries['changed']->changedNote = 'The count reads better after the title.';
        $entries['changed']->changedAt = $now->modify('-40 minutes');
        $entries['changed']->changedBy = $context->devUser();

        foreach ($entries as $key => $entry) {
            $id = DevSeedId::of('inbox/decision/'.$key);
            if (null !== $this->inboxDecisions->find($id)) {
                continue;
            }
            $context->ids->assign($entry, $id);
            $this->em->persist($entry);
        }
        $this->em->flush();
    }
}
