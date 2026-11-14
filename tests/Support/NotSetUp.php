<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/** Puts a flushed project back to the state of a new one, the state the gate and the milestones act on. */
final class NotSetUp
{
    public static function mark(EntityManagerInterface $em, Project $project): void
    {
        $em->getConnection()->executeStatement('UPDATE projects SET set_up = false WHERE id = ?', [(string) $project->id]);
        $project->setUp = false;
    }
}
