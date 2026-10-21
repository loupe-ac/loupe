<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The marked image a reviewer saved with a note. The database deletes the row
 * with its comment, so no handler has to remember it.
 */
#[ORM\Entity(repositoryClass: SiteReviewScreenshotRepository::class)]
#[ORM\Table(name: 'site_review_screenshots')]
class SiteReviewScreenshot
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: SiteReviewComment::class)]
        public readonly SiteReviewComment $comment,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 20)]
        public readonly string $contentType,

        /** A string when new, a stream resource once Doctrine has loaded it. Read it with contents(). */
        #[ORM\Column(type: Types::BLOB)]
        public readonly mixed $bytes,

        #[ORM\Column]
        public readonly int $size,

        #[ORM\Column]
        public readonly int $width,

        #[ORM\Column]
        public readonly int $height,
    ) {
    }

    public function contents(): string
    {
        if (\is_resource($this->bytes)) {
            rewind($this->bytes);

            return stream_get_contents($this->bytes) ?: '';
        }

        return \is_string($this->bytes) ? $this->bytes : throw new \LogicException('Screenshot holds no content.');
    }
}
