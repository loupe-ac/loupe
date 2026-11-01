<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The cost that OpenRouter billed for one worker run. The row outlives its run,
 * so the run id is a scalar and the retention sweep keeps the spend of a card.
 * The run key is the OpenRouter session, which is not the run id. Only
 * RecordBilledCostHandler changes a row after it is created. No property is
 * readonly, because the locked read refreshes the row.
 */
#[ORM\Entity(repositoryClass: WorkerRunBilledCostRepository::class)]
#[ORM\Index(name: 'idx_bridge_worker_run_billed_costs_subject', columns: ['project_id', 'subject_type', 'subject_id'])]
#[ORM\Table(name: 'bridge_worker_run_billed_costs')]
class WorkerRunBilledCost
{
    #[ORM\Column(name: 'state', length: 20, enumType: BilledCostState::class)]
    public BilledCostState $state = BilledCostState::Pending;

    #[ORM\Column(name: 'reason', length: 20, nullable: true, enumType: BilledCostReason::class)]
    public ?BilledCostReason $reason = null;

    /** Null until the state is billed. */
    #[ORM\Column(name: 'cost_usd', type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    public ?string $costUsd = null;

    /** The reads made so far. */
    #[ORM\Column(name: 'attempts')]
    public int $attempts = 0;

    /** The last total that no second read has confirmed yet. */
    #[ORM\Column(name: 'first_read_cost', type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    public ?string $firstReadCost = null;

    #[ORM\Column(name: 'updated_at')]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        /** The id of the run, a scalar with no foreign key. */
        #[ORM\Column(name: 'run_id', type: UuidType::NAME)]
        #[ORM\Id]
        public Uuid $runId,

        /** The session that OpenRouter groups the replies of the run by. */
        #[ORM\Column(name: 'run_key', type: UuidType::NAME)]
        public Uuid $runKey,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'subject_type', length: WorkSubject::MAX_TYPE_LENGTH)]
        public string $subjectType,

        /** A scalar, never a foreign key, like the subject of the run. */
        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        public Uuid $subjectId,

        #[ORM\Column(name: 'run_started_at')]
        public \DateTimeImmutable $runStartedAt,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
