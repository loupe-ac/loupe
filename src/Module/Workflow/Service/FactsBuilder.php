<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Doctrine\DBAL\Connection;

/** Reads what the engine knows about one card: its slots, and the facts of every provider. It logs nothing. */
final readonly class FactsBuilder
{
    public const string BACKLOG_SLOT = SlotKeys::BACKLOG;

    public const string TERMINAL_SLOT = SlotKeys::TERMINAL;

    private const string SAVEPOINT = 'workflow_fact_provider';

    public function __construct(
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private CardDirectory $cards,
        private ProjectRepository $projects,
        private FactProviders $providers,
        private Connection $connection,
        private PassMemo $pass,
    ) {
    }

    public function build(CardSnapshot $snapshot, \DateTimeImmutable $now): Facts
    {
        $provided = $this->pass->during(fn (): array => $this->providedByClass($snapshot));
        $pullRequests = $provided[PullRequestList::class][0] ?? null;
        $card = $this->cards->findWithParentColumn($snapshot->id) ?? throw new \LogicException('A stored card has an id.');
        $project = $this->projects->find($snapshot->projectId) ?? throw new \LogicException('A stored card has a project.');

        return new Facts(
            now: $now,
            slot: $this->slotOfRef($project, $card->column),
            parentSlot: null === $card->parentColumn ? null : $this->slotOfRef($project, $card->parentColumn),
            pullRequest: $pullRequests instanceof PullRequestList ? $pullRequests->primary : null,
            provided: array_map(static fn (array $result): object => $result[0], $provided),
            fingerprints: array_map(static fn (array $result): mixed => $result[1], array_filter($provided, static fn (array $result): bool => !$result[0] instanceof Unreadable)),
            legacyGroups: array_map(static fn (FactProvider&LegacyFingerprintGroup $provider): string => $provider->legacyGroup(), array_filter($this->providers->byClass, static fn (FactProvider $provider): bool => $provider instanceof LegacyFingerprintGroup)),
        );
    }

    /**
     * One savepoint covers the queries of every provider, so a database error leaves the transaction of the caller usable.
     * Rolling back to it keeps it open, so only the failing provider loses its facts.
     *
     * @return array<class-string, array{object, mixed}>
     */
    private function providedByClass(CardSnapshot $card): array
    {
        $savepoint = $this->connection->isTransactionActive();
        if ($savepoint) {
            $this->connection->createSavepoint(self::SAVEPOINT);
        }
        $provided = array_map(fn (FactProvider $provider): array => $this->provided($provider, $card, $savepoint), $this->providers->byClass);
        if ($savepoint) {
            $this->release();
        }

        return $provided;
    }

    /**
     * The facts of the provider and their fingerprint. A provider only reads, so a rollback to the shared savepoint loses nothing but its own facts.
     *
     * @return array{object, mixed}
     */
    private function provided(FactProvider $provider, CardSnapshot $card, bool $savepoint): array
    {
        $source = $provider::class;
        try {
            $source = $provider->source();
            $class = $provider->factsClass();
            $facts = $provider->isOn() ? $provider->build($card) : new Unreadable(UnreadableKind::Off, $source);
            $fingerprint = null;
            if (!$facts instanceof Unreadable) {
                if (!$facts instanceof $class) {
                    throw new \LogicException(\sprintf('The fact provider %s built a %s, not a %s.', $provider::class, $facts::class, $class));
                }
                $fingerprint = $provider->fingerprint($facts);
                json_encode($fingerprint, \JSON_THROW_ON_ERROR);
            }

            return [$facts, $fingerprint];
        } catch (\Throwable $e) {
            if ($savepoint) {
                $this->connection->rollbackSavepoint(self::SAVEPOINT);
            }

            return [new Unreadable(UnreadableKind::Failed, $source, $e), null];
        }
    }

    /** A provider that swallowed its own database error left the transaction aborted, and only a rollback to the savepoint makes it usable. */
    private function release(): void
    {
        try {
            $this->connection->releaseSavepoint(self::SAVEPOINT);
        } catch (\Throwable) {
            $this->connection->rollbackSavepoint(self::SAVEPOINT);
            $this->connection->releaseSavepoint(self::SAVEPOINT);
        }
    }

    public function slotOfRef(Project $project, ColumnRef $column): ?string
    {
        return match (true) {
            $column->backlog => self::BACKLOG_SLOT,
            $column->terminal => self::TERMINAL_SLOT,
            default => $this->workflowSlotLinks->findSlotKeyForColumnId($project, $column->id),
        };
    }
}
