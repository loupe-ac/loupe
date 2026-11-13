<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Every project that a test persists starts set up, so the pages the setup gate covers
 * stay reachable. A test of the gate or of the milestones calls NotSetUp::mark().
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class MarkNewProjectsSetUp
{
    /** A test of the dev seed turns the marking off, to see the flag the seed wrote. */
    public static bool $enabled = true;

    public function __invoke(PrePersistEventArgs $args): void
    {
        $project = $args->getObject();
        if (self::$enabled && $project instanceof Project) {
            $project->setUp = true;
        }
    }
}
