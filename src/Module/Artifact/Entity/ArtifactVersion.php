<?php

declare(strict_types=1);

namespace App\Module\Artifact\Entity;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactVersionRepository::class)]
#[ORM\Table(name: 'artifact_versions')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_version_number', columns: ['artifact_id', 'number'])]
class ArtifactVersion
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var Collection<int, ArtifactFile> */
    #[ORM\OneToMany(targetEntity: ArtifactFile::class, mappedBy: 'version', cascade: ['persist'])]
    #[ORM\OrderBy(['path' => 'ASC'])]
    public Collection $files;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Artifact::class, inversedBy: 'versions')]
        public readonly Artifact $artifact,

        /** Starts at 1 and counts up for each stored version of the artifact. */
        #[ORM\Column]
        public readonly int $number,

        /** Null when a person stored the version with no session, or the account is gone. */
        #[ORM\JoinColumn(nullable: true)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $storedBy = null,

        /** The session id the CLI sent, when it sent one. */
        #[ORM\Column(length: 255, nullable: true)]
        public readonly ?string $storedBySession = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->files = new ArrayCollection();
    }

    public function addFile(string $path, ArtifactBlob $blob, string $contentType): ArtifactFile
    {
        $file = new ArtifactFile($this, $path, $blob, $contentType);
        $this->files->add($file);

        return $file;
    }

    /** The file a viewer opens: index.html, else the first HTML file, else the first file. */
    public function entryFile(): ?ArtifactFile
    {
        $first = null;
        $firstHtml = null;
        foreach ($this->files as $file) {
            if ('index.html' === $file->path) {
                return $file;
            }
            $first ??= $file;
            if (null === $firstHtml && 1 === preg_match('/\.html?$/i', $file->path)) {
                $firstHtml = $file;
            }
        }

        return $firstHtml ?? $first;
    }
}
