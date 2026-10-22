<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** AgentReview asks this port which document tags get a design review, and the workflow template of the project names them. */
interface DesignReviewTags
{
    /** @return list<string> the tags of the documents whose rules request a design review, and none when the template asks for no review */
    public function of(Uuid $projectId): array;

    /**
     * @param string $columnId the id of a board column of the project
     *
     * @return list<string> the tags whose design review rules act in the slot that the column links, and none for a column that links no slot
     */
    public function reviewedInColumn(Uuid $projectId, string $columnId): array;
}
