<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;

/** Gives a project a repository whose stored check holds a blocking gap, or none. */
trait BlockingRepositories
{
    private int $repositorySequence = 0;

    /** @param list<string> $gaps */
    private function repositoryWithGaps(Project $project, array $gaps = ['no_pull_request_rule']): ForgeRepository
    {
        $em = $this->em();
        $repository = new ForgeRepository($project, 'github', 'ext-'.++$this->repositorySequence.uniqid(), 'acme/repo-'.$this->repositorySequence, ForgeRepositorySource::Hook);
        $em->persist($repository);
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->items = [['key' => 'approval-gate', 'mark' => 'blocking', 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []]];
        $em->persist($check);
        $em->flush();

        return $repository;
    }
}
