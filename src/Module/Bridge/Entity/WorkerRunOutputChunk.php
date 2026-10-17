<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One append to the live output log of a run. The chunks of a run are
 * contiguous: each starts where the one before it ends. The chunk that hit the
 * size cap is marked, and it ends the log.
 */
#[ORM\Entity(repositoryClass: WorkerRunOutputChunkRepository::class)]
#[ORM\Table(name: 'bridge_worker_run_output_chunks')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_output_chunk_start', columns: ['run_id', 'start_offset'])]
class WorkerRunOutputChunk
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        // The retention sweep deletes runs with DQL, so only the database can remove the row.
        #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public readonly WorkerRun $run,

        /** The byte offset of the first byte of the text. */
        #[ORM\Column(name: 'start_offset')]
        public readonly int $startOffset,

        /** The byte length of the text. */
        #[ORM\Column(name: 'length')]
        public readonly int $length,

        #[ORM\Column(name: 'text', type: Types::TEXT)]
        public readonly string $text,

        #[ORM\Column(name: 'cut')]
        public readonly bool $cut = false,

        #[ORM\Column(name: 'created_at')]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
