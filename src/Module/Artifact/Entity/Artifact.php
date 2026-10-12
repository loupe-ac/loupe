<?php

declare(strict_types=1);

namespace App\Module\Artifact\Entity;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;
use App\Security\ProjectScopedSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactRepository::class)]
#[ORM\Table(name: 'artifacts')]
class Artifact implements ProjectScopedSubject
{
    public const int MAX_NAME_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** Set when the artifact is archived and cleared when it is restored. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $archivedAt = null;

    /** @var Collection<int, ArtifactVersion> */
    #[ORM\OneToMany(targetEntity: ArtifactVersion::class, mappedBy: 'artifact', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => 'ASC'])]
    public Collection $versions;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        public string $name,

        /** Null when the account that stored the first version is gone. */
        #[ORM\JoinColumn(nullable: true)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $createdBy = null,

        /** Where the files came from, such as the link of a design canvas. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $sourceLink = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->versions = new ArrayCollection();
    }

    public function addVersion(?User $storedBy = null, ?string $storedBySession = null): ArtifactVersion
    {
        $version = new ArtifactVersion($this, $this->versions->count() + 1, $storedBy, $storedBySession);
        $this->versions->add($version);

        return $version;
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'artifact';
    }
}
