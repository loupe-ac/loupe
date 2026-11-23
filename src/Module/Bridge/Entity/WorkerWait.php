<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use App\Security\ProjectScopedSubject;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One wait of a worker: the conditions of its card that it waits for, and the time limit.
 * A card has at most one open wait. Each run has at most one wait, because the wait ends the run.
 *
 * @phpstan-type ConditionEntry array{key: string, params: array<string, mixed>, pullRequestId: ?string, token: ?string}
 */
#[ORM\Entity(repositoryClass: WorkerWaitRepository::class)]
#[ORM\Index(name: 'idx_bridge_worker_waits_chain', columns: ['chain_root_run_id'])]
#[ORM\Table(name: 'bridge_worker_waits')]
// The predicate is written the way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_wait_run', columns: ['run_id'])]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_wait_open_card', columns: ['card_id'], options: ['where' => '(ended_at IS NULL)'])]
class WorkerWait implements ProjectScopedSubject
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'end_reason', length: 20, nullable: true, enumType: WorkerWaitEndReason::class)]
    public ?WorkerWaitEndReason $endReason = null;

    /**
     * The conditions that were true when the wait ended by one of them.
     *
     * @var list<ConditionEntry>|null
     */
    #[ORM\Column(name: 'met_conditions', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $metConditions = null;

    /** The run that resumed the session after the wait ended. A plain id, because the run can be purged first. */
    #[ORM\Column(name: 'resume_run_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $resumeRunId = null;

    /**
     * @param list<ConditionEntry> $conditions
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** A scalar, never a foreign key, like the card of a run. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        /** The run that opened the wait. */
        #[ORM\Column(name: 'run_id', type: UuidType::NAME)]
        public readonly Uuid $runId,

        /** The first run of the chain of resumed runs that the run belongs to. */
        #[ORM\Column(name: 'chain_root_run_id', type: UuidType::NAME)]
        public readonly Uuid $chainRootRunId,

        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME)]
        public readonly Uuid $bridgeId,

        #[ORM\Column(name: 'session_id', type: UuidType::NAME)]
        public readonly Uuid $sessionId,

        #[ORM\Column(name: 'conditions', type: Types::JSON, options: ['jsonb' => true])]
        public array $conditions,

        #[ORM\Column(name: 'opened_at')]
        public readonly \DateTimeImmutable $openedAt,

        #[ORM\Column(name: 'limit_at')]
        public \DateTimeImmutable $limitAt,
    ) {
    }

    public function isOpen(): bool
    {
        return null === $this->endedAt;
    }

    /**
     * Ends an open wait. A wait that has ended keeps its first end, so the call answers false and changes nothing.
     *
     * @param list<ConditionEntry> $met
     */
    public function end(WorkerWaitEndReason $reason, \DateTimeImmutable $at, array $met = []): bool
    {
        if (!$this->isOpen()) {
            return false;
        }

        $this->endedAt = $at;
        $this->endReason = $reason;
        $this->metConditions = [] === $met ? null : $met;

        return true;
    }

    /** Whether the session of the wait still needs a resume: the wait is open, or it ended in a way that resumes and no run resumed it yet. */
    public function awaitsResume(): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        return null === $this->resumeRunId && true === $this->endReason?->resumesSession();
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'worker_wait';
    }
}
