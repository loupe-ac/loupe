<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Writes the decisions of the dev cards, so the Decisions tab shows a defaulted, a logged and a changed
 * entry. The basket card has decisions and no open item, which gives the inbox a digest-only group. The
 * not grouped project holds two cards with decisions, which gives the flat digest list. A re-seed restores
 * an entry that is missing and leaves the others as they are.
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
        private BoardColumnRepository $boardColumns,
        private WorkflowBindingRepository $workflowBindings,
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
        $basket = $this->cards->find(DevBoardSeeder::cardId('empty-basket'))
            ?? throw new \LogicException('The card empty-basket does not exist.');
        $now = new \DateTimeImmutable();
        $flatProject = $this->projects->find(DevProjectSeeder::inboxFlatProjectId())
            ?? throw new \LogicException('The not grouped inbox project does not exist.');
        $this->turnOffGrouping($flatProject);
        $flatOne = $this->flatCard($context, $flatProject, 'one', 'Name the export file after the project');
        $flatTwo = $this->flatCard($context, $flatProject, 'two', 'Keep the export in one file');

        $entries = [
            'defaulted' => new InboxDecision($project, $checkout, 'test-choice', 'Cache the column counts for 30 s', ['Cache for 5 min'], InboxDecisionMadeBy::Defaulted, $now->modify('-3 hours')),
            'logged' => new InboxDecision($project, $checkout, 'naming', 'Named the cache key board.columnCounts', ['board.counts', 'columns.cardCount'], InboxDecisionMadeBy::Logged, $now->modify('-2 hours')),
            'changed' => new InboxDecision($project, $checkout, 'ordering', 'Moved the count above the column title', ['Count after the title'], InboxDecisionMadeBy::Logged, $now->modify('-1 hour')),
            'history' => new InboxDecision($project, $history, 'wording', 'Wrote the empty column text as "No cards here yet"', [], InboxDecisionMadeBy::Logged, $now->modify('-30 minutes')),
            'basket-first' => new InboxDecision($project, $basket, 'wording', 'Worded the empty basket as "Nothing here yet"', [], InboxDecisionMadeBy::Logged, $now->modify('-20 minutes')),
            'basket-second' => new InboxDecision($project, $basket, 'icon', 'Used the basket icon for the empty state', ['No icon'], InboxDecisionMadeBy::Defaulted, $now->modify('-10 minutes')),
            'flat-one' => new InboxDecision($flatProject, $flatOne, 'naming', 'Named the file after the project', ['A fixed file name'], InboxDecisionMadeBy::Logged, $now->modify('-15 minutes')),
            'flat-two' => new InboxDecision($flatProject, $flatTwo, 'format', 'Wrote the export as one CSV file', ['One file per table'], InboxDecisionMadeBy::Defaulted, $now->modify('-5 minutes')),
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

    private function turnOffGrouping(Project $project): void
    {
        $binding = $this->workflowBindings->findOneByProjectId($project->id ?? throw new \LogicException('A stored project has an id.'))
            ?? throw new \LogicException('The not grouped inbox project has no template.');
        $definition = $binding->definition;
        $asks = $definition['asks'] ?? [];
        $definition['asks'] = [...\is_array($asks) ? $asks : [], 'groupByCard' => false];
        $binding->definition = $definition;
    }

    private function flatCard(SeedContext $context, Project $project, string $slug, string $title): Card
    {
        $id = DevSeedId::of('inbox/flat-card/'.$slug);
        $existing = $this->cards->find($id);
        if ($existing instanceof Card) {
            return $existing;
        }

        $column = $this->boardColumns->findForProject($project)[0] ?? throw new \LogicException('The project has no column.');
        $card = new Card(project: $project, column: $column, title: $title, body: '', number: $this->cards->nextNumber($project), type: 'feature');
        $context->ids->assign($card, $id);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
