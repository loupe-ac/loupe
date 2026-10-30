<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopConnection
{
    public function __construct(
        public string $id,
        public string $cliVersion,
        public bool $quiet,
        public string $url,
        /** What a page calls the bridge: its name, or the tail of its id. */
        public string $label,
        /** The git identity the bridge reported for the project, or null when it reported none. */
        public ?WorkshopGitIdentity $gitIdentity = null,
        /** The folder the bridge reported for the project, or null when it reported none. */
        public ?string $repositoryPath = null,
        /** The origin remote the bridge reported for that folder. Empty when git gave none, null when the bridge reported no folder. */
        public ?string $repositoryRemoteUrl = null,
        /** Whether the CLI of the bridge reports git identities. An older CLI does not, and keeps the git setup of its machine. */
        public bool $reportsGitIdentities = false,
        /** Whether the bridge is quiet because its login can no longer refresh. */
        public bool $loginExpired = false,
    ) {
    }
}
