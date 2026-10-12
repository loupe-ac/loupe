<?php

declare(strict_types=1);

namespace App\Module\Artifact\Entity;

use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactBlobRepository::class)]
#[ORM\Table(name: 'artifact_blobs')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_blob_hash', columns: ['project_id', 'sha256'])]
class ArtifactBlob
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 64, options: ['fixed' => true])]
        public readonly string $sha256,

        /** A string when new, a stream resource once Doctrine has loaded it. Read it with contents(). */
        #[ORM\Column(type: Types::BLOB)]
        public readonly mixed $bytes,

        #[ORM\Column]
        public readonly int $size,
    ) {
    }

    public function contents(): string
    {
        if (\is_resource($this->bytes)) {
            rewind($this->bytes);

            return stream_get_contents($this->bytes) ?: '';
        }

        return \is_string($this->bytes) ? $this->bytes : throw new \LogicException('Blob holds no content.');
    }
}
