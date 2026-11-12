<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class WorkflowFileReadQueues
{
    /** @param iterable<WorkflowFileReads> $reads */
    public function __construct(
        #[AutowireIterator('app.workflow_file_reads')]
        private iterable $reads,
    ) {
    }

    public function for(string $forge): ?WorkflowFileReads
    {
        foreach ($this->reads as $reads) {
            if ($reads->supports($forge)) {
                return $reads;
            }
        }

        return null;
    }
}
