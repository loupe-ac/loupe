<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Entity\ExperimentArchive;

final class ExperimentArchiveState
{
    /**
     * An experiment is archived when its archive row exists and every run that
     * names it from the archive second on was already there at the archive.
     *
     * @param list<string> $runIdsFromArchiveSecond the ids of the runs that name the experiment now and were received in the archive second or later
     */
    public static function isArchived(?ExperimentArchive $archive, array $runIdsFromArchiveSecond): bool
    {
        return null !== $archive && [] === array_diff($runIdsFromArchiveSecond, $archive->coveredRunIds);
    }
}
