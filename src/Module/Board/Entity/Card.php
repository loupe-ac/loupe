<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardTypes;
use App\Security\ProjectScopedSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CardRepository::class)]
// The board reads one column at a time, then sorts by position.
#[ORM\Index(name: 'idx_board_cards_column_position', columns: ['column_id', 'position'])]
// The minute task reads these two to find the cards whose markers are due or not read by this version of the engine yet.
#[ORM\Index(name: 'idx_board_cards_workflow_markers_due', columns: ['workflow_markers_due_at'], options: ['where' => '(workflow_markers_due_at IS NOT NULL)'])]
#[ORM\Index(name: 'idx_board_cards_inbox_markers_missing', columns: ['project_id'], options: ['where' => '(inbox_markers IS NULL)'])]
#[ORM\Table(name: 'board_cards')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_project_number', columns: ['project_id', 'number'])]
class Card implements ProjectScopedSubject
{
    /** Mirrors the title column's length so callers can reject an over-long title before Postgres does. */
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * Set when the card enters a terminal column and cleared when it leaves for
     * a column that is not terminal. A terminal column sorts on this rather
     * than on $position, which it does not maintain.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    /** Counts each entry into a terminal column from outside one, so two completions in one second still differ. */
    #[ORM\Column(options: ['default' => 0])]
    public int $completions = 0;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /**
     * The board set: the markers the workflow engine found on the card, one per key, as stored by CardMarkerStore. Null until the first pass.
     *
     * @var list<array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string, link?: ?string}>|null
     */
    #[ORM\Column(name: 'workflow_markers', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $markers = null;

    /** The time the next timed marker may change, or null when only an event changes them. */
    #[ORM\Column(name: 'workflow_markers_due_at', nullable: true)]
    public ?\DateTimeImmutable $markersDueAt = null;

    /**
     * The inbox set: one entry per thing for each marker that the template lists under `inbox`. Null until this version of the engine reads the card.
     *
     * @var list<array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string, link?: ?string, subject?: array{type: string, ids: array<string, string>}}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $inboxMarkers = null;

    /**
     * The epic this card belongs to. CardParentPolicy keeps it an epic of the
     * same project. The key has no ON DELETE action, so the project delete
     * removes a parent and its children in one statement.
     */
    #[ORM\JoinColumn(name: 'parent_card_id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?Card $parent = null;

    /** Whether the board draws this epic as a lane. Only an epic reads it. */
    #[ORM\Column(name: 'lane_enabled', options: ['default' => true])]
    public bool $laneEnabled = true;

    /** Whether a session run brought the card into its column. Any other move to another column clears it. */
    #[ORM\Column(name: 'arrived_in_session', options: ['default' => false])]
    public bool $arrivedInSession = false;

    /** Whether a person must review this card's pull request before it merges. */
    #[ORM\Column(name: 'requires_human_review', options: ['default' => false])]
    public bool $requiresHumanReview = false;

    /**
     * The ids of the artifacts that reached the card when the switch last changed. An artifact in this list
     * has already been weighed by whoever set the switch.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'human_review_seen_artifacts', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $humanReviewSeenArtifacts = [];

    /**
     * Only an epic reads it: each blocker outside the epic whose code a merge of the default branch brought into
     * the epic branch, by card id, with the completion count the blocker had then.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $epicBranchSyncedBlockers = [];

    /**
     * Only an epic reads it: each fallback merge run still live, by work request id, with the blocker completion
     * counts at its open. The run merges no earlier code than that, so its end records those counts and no newer ones.
     *
     * @var array<string, array<string, int>>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $epicBranchPendingSyncs = [];

    /** @var Collection<int, CardPullRequest> */
    #[ORM\OneToMany(targetEntity: CardPullRequest::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedAt' => 'ASC'])]
    public Collection $pullRequests;

    /** @var Collection<int, CardDocument> */
    #[ORM\OneToMany(targetEntity: CardDocument::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['linkedAt' => 'ASC'])]
    public Collection $documents;

    /** @var Collection<int, CardArtifact> */
    #[ORM\OneToMany(targetEntity: CardArtifact::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['linkedAt' => 'ASC'])]
    public Collection $artifacts;

    /**
     * Null on a row an image without this column wrote, which is why nothing
     * reads it directly. Read $reporter instead.
     */
    #[ORM\Column(name: 'reporter', length: 20, nullable: true, enumType: Actor::class)]
    private ?Actor $storedReporter = null;

    /** Who raised the card, falling back to the column release 2 drops. */
    public Actor $reporter {
        get => $this->storedReporter ?? $this->origin;
    }

    /**
     * Null on a row an image without this column wrote, which is why nothing
     * reads it directly. Read $source instead.
     */
    #[ORM\Column(name: 'source', length: 20, nullable: true, enumType: CardSourceKind::class)]
    private ?CardSourceKind $sourceKind = null;

    /** No foreign key, so the source survives the delete of the run. */
    #[ORM\Column(name: 'source_run_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceRunId = null;

    /** No foreign key, for the same reason. */
    #[ORM\Column(name: 'source_run_card_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceRunCardId = null;

    /** Where the card came from, falling back to the reporter on a row with no stored source. */
    public CardSource $source {
        get => null === $this->sourceKind
            ? CardSource::fromReporter($this->reporter)
            : new CardSource($this->sourceKind, $this->sourceRunId, $this->sourceRunCardId);
    }

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(name: 'column_id', nullable: false)]
        #[ORM\ManyToOne(targetEntity: BoardColumn::class)]
        public BoardColumn $column,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        /** The short number a person says out loud, counting from 1 inside the project. Never unique across projects. */
        #[ORM\Column]
        public readonly int $number,

        /** The key of a type the project's workflow template declares. */
        #[ORM\Column(length: 20)]
        public string $type = 'feature',

        /** The column release 2 drops. Every write sets it, so an older image still reads the row. */
        #[ORM\Column(length: 20, enumType: Actor::class)]
        public readonly Actor $origin = Actor::Agent,

        /** Rank inside the card's column, counting from 0. A terminal column ignores it. */
        #[ORM\Column]
        public int $position = 0,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        /* Null derives the source from $origin. */
        ?CardSource $source = null,
    ) {
        $source ??= CardSource::fromReporter($this->origin);
        $this->sourceKind = $source->kind;
        $this->sourceRunId = $source->runId;
        $this->sourceRunCardId = $source->runCardId;
        $this->storedReporter = $this->origin;
        $this->pullRequests = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->artifacts = new ArrayCollection();
        $this->updatedAt = $this->createdAt;
    }

    /** The text an edit form opened with, so a save can tell whether someone changed it since. */
    public static function contentFingerprint(string $title, string $body): string
    {
        return hash('sha256', self::normalText($title)."\0".self::normalText($body));
    }

    /**
     * Text as the web form submits it: trimmed, with Unix line ends. An MCP
     * tool stores a body as given, so compare text in this form, never raw.
     */
    public static function normalText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** Stamps the first entry into a terminal column. A move between two terminal columns keeps the stamp. */
    public function complete(\DateTimeImmutable $now): void
    {
        if (null === $this->completedAt) {
            $this->completedAt = $now;
            ++$this->completions;
        }
    }

    /** Records on the epic that its branch now holds the code of each blocker, as each blocker stands now. */
    public function recordEpicBranchSync(Card ...$blockers): void
    {
        foreach ($blockers as $blocker) {
            $this->epicBranchSyncedBlockers[(string) $blocker->id] = $blocker->completions;
        }
    }

    /** Notes the blockers that a fallback merge run covers, as each blocker stands when the run opens. */
    public function awaitEpicBranchSync(Uuid $requestId, Card ...$blockers): void
    {
        $counts = [];
        foreach ($blockers as $blocker) {
            $counts[(string) $blocker->id] = $blocker->completions;
        }
        $this->epicBranchPendingSyncs[$requestId->toRfc4122()] = $counts;
    }

    /**
     * Ends a fallback merge run. A run that merged records its counts. Two runs can end out of order, so a lower count never replaces a higher one.
     *
     * @return bool whether the epic awaited the run
     */
    public function settleEpicBranchSync(Uuid $requestId, bool $merged): bool
    {
        $counts = $this->epicBranchPendingSyncs[$requestId->toRfc4122()] ?? null;
        if (null === $counts) {
            return false;
        }
        unset($this->epicBranchPendingSyncs[$requestId->toRfc4122()]);
        if ($merged) {
            foreach ($counts as $blockerId => $count) {
                $this->epicBranchSyncedBlockers[$blockerId] = max($count, $this->epicBranchSyncedBlockers[$blockerId] ?? 0);
            }
        }

        return true;
    }

    /** A blocker that finished again after the merge reads as not held, because its stamp changed. */
    public function epicBranchHolds(Card $blocker): bool
    {
        return ($this->epicBranchSyncedBlockers[(string) $blocker->id] ?? null) === $blocker->completions;
    }

    /** What the workflow reads about this card when it decides on a move. */
    public function snapshot(): CardSnapshot
    {
        return new CardSnapshot(
            $this->id ?? throw new \LogicException('A stored card has an id.'),
            $this->project->id ?? throw new \LogicException('A stored project has an id.'),
            $this->number,
            $this->type,
            $this->column->ref(),
            $this->parent?->id,
            $this->parent?->number,
            $this->parent?->column->ref(),
            $this->arrivedInSession,
        );
    }

    /** Whether the board draws this card as a lane of its own. */
    public function drawsLane(CardTypes $types): bool
    {
        return $types->get($this->type)->lane && $this->laneEnabled && !$this->column->terminal;
    }

    /**
     * Makes the card's pull request links match the given set exactly.
     *
     * A diff by URL. A URL that stays keeps its row, so it keeps its addedAt and
     * its predatesLinkTimes flag. The given object for such a URL is dropped.
     * A removed URL leaves the collection, and a new one is added after it.
     */
    public function replacePullRequests(CardPullRequest ...$links): void
    {
        $wanted = [];
        foreach ($links as $link) {
            $wanted[$link->url] ??= $link;
        }

        foreach ($this->pullRequests as $existing) {
            if (isset($wanted[$existing->url])) {
                unset($wanted[$existing->url]);

                continue;
            }
            $this->pullRequests->removeElement($existing);
        }

        foreach ($wanted as $link) {
            $this->pullRequests->add($link);
        }
    }

    /**
     * Makes the card's document links match the given set exactly.
     *
     * A diff rather than a clear-and-rebuild, which is what the pull request
     * links do. Doctrine issues inserts before orphan-removal deletes, so
     * rebuilding puts a second row with the same (card, document) pair on the
     * wire before the first is gone, and the unique index refuses it.
     */
    public function syncDocuments(Document ...$documents): void
    {
        $wanted = [];
        foreach ($documents as $document) {
            $wanted[(string) $document->id] = $document;
        }

        foreach ($this->documents as $link) {
            $id = (string) $link->document->id;
            if (isset($wanted[$id])) {
                unset($wanted[$id]);

                continue;
            }
            $this->documents->removeElement($link);
        }

        foreach ($wanted as $document) {
            $this->documents->add(new CardDocument($this, $document));
        }
    }

    /**
     * The ids of the artifacts linked to this card or to its parent, in id order.
     *
     * @return list<string>
     */
    public function reachingArtifactIds(): array
    {
        $ids = [];
        foreach ($this->artifacts as $link) {
            $ids[(string) $link->artifact->id] = true;
        }
        if (null !== $this->parent) {
            foreach ($this->parent->artifacts as $link) {
                $ids[(string) $link->artifact->id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /** Links the artifact unless the card already carries it. */
    public function linkArtifact(Artifact $artifact): void
    {
        foreach ($this->artifacts as $link) {
            if ($link->artifact->id?->equals($artifact->id) ?? false) {
                return;
            }
        }

        $this->artifacts->add(new CardArtifact($this, $artifact));
    }

    /** Makes the card's artifact links match the given set exactly, as syncDocuments does for documents. */
    public function syncArtifacts(Artifact ...$artifacts): void
    {
        $wanted = [];
        foreach ($artifacts as $artifact) {
            $wanted[(string) $artifact->id] = $artifact;
        }

        foreach ($this->artifacts as $link) {
            $id = (string) $link->artifact->id;
            if (isset($wanted[$id])) {
                unset($wanted[$id]);

                continue;
            }
            $this->artifacts->removeElement($link);
        }

        foreach ($wanted as $artifact) {
            $this->artifacts->add(new CardArtifact($this, $artifact));
        }
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'card';
    }
}
