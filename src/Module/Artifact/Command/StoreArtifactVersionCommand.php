<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Account\Entity\User;

final readonly class StoreArtifactVersionCommand
{
    /** @param list<ArtifactFileInput> $files */
    public function __construct(
        public User $owner,
        /** The id or the slug of the project. */
        public string $handle,
        /** The artifact to add a version to. Null stores a new artifact. */
        public ?string $artifactId,
        /** Names a new artifact. Ignored when $artifactId is set. */
        public ?string $name,
        public ?string $sourceLink,
        /** The session id the CLI sent. Null means a person stored it with no session. */
        public ?string $sessionId,
        public array $files,
        /** The id or the number of a card of the project to link the artifact to. Null links none. */
        public ?string $card = null,
    ) {
    }
}
