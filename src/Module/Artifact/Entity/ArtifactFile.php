<?php

declare(strict_types=1);

namespace App\Module\Artifact\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'artifact_files')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_file_path', columns: ['version_id', 'path'])]
class ArtifactFile
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: ArtifactVersion::class, inversedBy: 'files')]
        public readonly ArtifactVersion $version,

        /** Relative to the root of the version, with forward slashes. */
        #[ORM\Column(length: 1024)]
        public readonly string $path,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: ArtifactBlob::class)]
        public readonly ArtifactBlob $blob,

        #[ORM\Column(length: 255)]
        public readonly string $contentType,
    ) {
    }
}
