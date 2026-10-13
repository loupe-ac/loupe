<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Service\LinkedCard;

final readonly class ShowArtifactView
{
    public function __construct(
        public Artifact $artifact,
        public ArtifactVersion $version,
        /** @var list<ArtifactVersion> newest first */
        public array $versions,
        /** @var list<LinkedCard> */
        public array $cards,
        /** @var list<ArtifactFile> the web pages the file menu lists, in path order */
        public array $files,
        /** The file in the frame, or null when the version holds no file. */
        public ?ArtifactFile $file,
        /** True when the frame shows the main file of the version. */
        public bool $mainFile,
        /** Signed link of the file the frame opens, or null when the version holds no file. */
        public ?string $contentLink,
        /** True when the link points at the content domain. */
        public bool $ownContentOrigin,
        /** @var list<string> sites a stored page may load from */
        public array $allowedOrigins,
    ) {
    }
}
