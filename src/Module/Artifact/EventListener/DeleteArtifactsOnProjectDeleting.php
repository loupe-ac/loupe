<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactBlob;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the artifacts of a project, inside ProjectDeleter's transaction.
 * The files go first, then the versions and artifacts that hold them, then the
 * blobs the files pointed at. A card link goes with its artifact, through the
 * cascade on the link table.
 */
#[AsEventListener]
final readonly class DeleteArtifactsOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(\sprintf(
            'DELETE %s f WHERE f.version IN (SELECT v.id FROM %s v JOIN v.artifact a WHERE a.project = :project)',
            ArtifactFile::class,
            ArtifactVersion::class,
        ))->setParameter('project', $event->project)->execute();

        $this->em->createQuery(\sprintf(
            'DELETE %s v WHERE v.artifact IN (SELECT a.id FROM %s a WHERE a.project = :project)',
            ArtifactVersion::class,
            Artifact::class,
        ))->setParameter('project', $event->project)->execute();

        $this->em->createQuery(\sprintf('DELETE %s a WHERE a.project = :project', Artifact::class))
            ->setParameter('project', $event->project)->execute();

        $this->em->createQuery(\sprintf('DELETE %s b WHERE b.project = :project', ArtifactBlob::class))
            ->setParameter('project', $event->project)->execute();
    }
}
