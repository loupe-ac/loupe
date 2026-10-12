<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The text fields of the multipart store request. The files arrive beside it as `files[]`. */
final readonly class StoreArtifactVersionRequest
{
    /** @param list<string> $paths the path of each file in `files[]`, in the same order */
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $name = null,

        #[Assert\Length(max: 36)]
        public ?string $artifactId = null,

        #[Assert\Length(max: 2000)]
        public ?string $sourceLink = null,
        public array $paths = [],

        #[Assert\Length(max: 36)]
        public ?string $card = null,

        #[Assert\Length(max: 2000)]
        public ?string $notes = null,
    ) {
    }
}
