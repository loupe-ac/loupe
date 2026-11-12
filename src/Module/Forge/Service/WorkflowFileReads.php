<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Queues a read of `.loupe.yaml` for a repository row. A forge module implements it. */
#[AutoconfigureTag('app.workflow_file_reads')]
interface WorkflowFileReads
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @param bool $force a forced read reconciles also when the file is unchanged, and tries again when the forge fails
     */
    public function queue(ForgeRepository $repository, bool $force): void;
}
