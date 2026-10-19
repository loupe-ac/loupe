<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The number of failed reviews after which the design review loop of a project publishes a document. */
interface DesignReviewRoundLimit
{
    /**
     * @param list<string> $tags the tags of one document
     *
     * @return ?int null when the workflow has no rule that ends a review loop for the tags
     */
    public function of(Uuid $projectId, array $tags): ?int;
}
