<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The Inbox asks this port how the `asks:` section of the project template shapes the inbox. */
interface InboxAskSettings
{
    /** Whether the open list groups its rows by card. A project bound to no template says yes. */
    public function groupByCard(Uuid $projectId): bool;

    /**
     * How the template treats the label. An unlisted label, a mode whose switch is off and a project bound to
     * no template all give `ask`.
     */
    public function labelRule(Uuid $projectId, string $label): AskLabelRule;
}
