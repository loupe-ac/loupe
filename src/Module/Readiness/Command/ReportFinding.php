<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** One check of a discovery report, as the worker states it. */
final readonly class ReportFinding
{
    public const string OWNER = 'owner';

    public const string AGENT = 'agent';

    public const string NONE = 'none';

    public const array ACTORS = [self::OWNER, self::AGENT, self::NONE];

    public function __construct(
        public string $check,
        /** Ready or suggested. An older plugin sends gap for suggested. */
        public string $status,
        public string $evidence,
        /** Who acts on a suggested finding: owner, agent or none. An older plugin sends none. */
        public string $actor = self::NONE,
        /** One sentence that says what the actor does. An older plugin sends none. */
        public string $action = '',
    ) {
    }
}
