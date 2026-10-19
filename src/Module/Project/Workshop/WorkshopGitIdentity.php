<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** The git identity that a bridge reported for one project on its machine. */
final readonly class WorkshopGitIdentity
{
    public const string MODE_AGENT_ACCOUNT = 'agent_account';

    public const string MODE_APP_TOKEN = 'app_token';

    public const string MODE_MACHINE = 'machine';

    /**
     * @param self::MODE_* $mode
     */
    public function __construct(
        public string $mode,
        /** The name git commits with. Empty when git gave none. */
        public string $commitName,
        /** The GitHub login the pushes use. Empty when it is unknown. */
        public string $pushLogin,
        public ?int $githubUserId,
        /** The server clock at the heartbeat that carried the row. */
        public ?\DateTimeImmutable $reportedAt,
    ) {
    }
}
