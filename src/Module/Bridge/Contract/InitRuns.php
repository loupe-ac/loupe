<?php

declare(strict_types=1);

namespace App\Module\Bridge\Contract;

use Symfony\Component\Uid\Uuid;

/** The interactive runs that `loupe init` opens on a project while it runs. */
interface InitRuns
{
    public const string WORK_KIND = 'init';

    public function isOpen(Uuid $projectId): bool;
}
