<?php

declare(strict_types=1);

namespace App\Module\Board\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardValueWriter;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes two cards in the Tech design column of the Lifecycle project. Both have the switch
 * "tech-design-interactive" On, an approved product design that the Workflow account approved, and no tech design.
 * The "ask" card has no answer yet, so the engine opens the session start question on its next pass.
 * The "approved" card holds "session-start-approved" On, written after the approval, so the question stays closed.
 * A later seed finds each card, link and value by its fixed id and writes only what is missing.
 */
#[When('dev')]
#[When('test')]
final readonly class DevSessionStartSeeder implements DevSeederInterface
{
    public const string KEY = 'board-session-start';

    public const string SWITCH_KEY = 'tech-design-interactive';
    public const string APPROVED_KEY = 'session-start-approved';

    /** The seconds before now at which each card was created, by state. */
    private const array AGE = ['ask' => 320, 'approved' => 310];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private DocumentRepository $documents,
        private CardValueWriter $values,
        private CardEvaluations $evaluations,
    ) {
    }

    public static function cardId(string $state): Uuid
    {
        return DevSeedId::of('board/card/session-start-'.$state);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevDocumentSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $ask = $this->card($context, 'ask', 'A card that waits for a session start answer');
        $approved = $this->card($context, 'approved', 'A card with the session start approved');

        $this->values->set($ask, self::SWITCH_KEY, true, Actor::System);
        $this->values->set($approved, self::SWITCH_KEY, true, Actor::System);
        $this->values->set($approved, self::APPROVED_KEY, true, Actor::System);
        $this->em->flush();

        $this->evaluations->forCards([self::cardId('ask'), self::cardId('approved')]);
    }

    private function card(SeedContext $context, string $state, string $title): Card
    {
        $card = $this->cards->find(self::cardId($state));
        if (!$card instanceof Card) {
            $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
                ?? throw new \LogicException('The lifecycle project does not exist.');
            $column = array_find($this->boardColumns->findForProject($project), static fn (BoardColumn $column): bool => 'tech-design' === $column->slug)
                ?? throw new \LogicException('The lifecycle project has no tech design column.');
            $card = new Card(
                project: $project,
                column: $column,
                title: $title,
                body: 'Shows the session start question and the approved state on the card page.',
                number: $this->cards->nextNumber($project),
                type: 'feature',
                createdAt: $context->at(self::AGE[$state]),
            );
            $context->ids->assign($card, self::cardId($state));
            $this->em->persist($card);
            // The arrival in the column is what a value write must come after, so the card needs this row.
            $this->cardEvents->record($card, CardEventKind::Created, Actor::Human, $context->devUser(), ['column' => CardEvent::columnDetail($column), 'type' => $card->type], $context->at(self::AGE[$state]));
            $this->em->flush();
        }

        $document = $this->documents->find(DevDocumentSeeder::documentId('session-start-'.$state))
            ?? throw new \LogicException('The session start product design is missing.');
        if (!$card->documents->exists(static fn (int $key, CardDocument $link): bool => $link->document === $document)) {
            $card->documents->add(new CardDocument($card, $document, $context->at(self::AGE[$state] - 5)));
            $this->em->flush();
        }

        return $card;
    }
}
