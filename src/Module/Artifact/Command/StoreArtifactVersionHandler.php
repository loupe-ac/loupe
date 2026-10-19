<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Exception\DomainErrors;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\ArtifactCardLinksInterface;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class StoreArtifactVersionHandler
{
    private const int MAX_PATH_LENGTH = 1024;
    private const int MAX_CONTENT_TYPE_LENGTH = 255;
    private const int MAX_SESSION_LENGTH = 255;
    // A Design canvas needs the runtime that only claude.ai supplies, so it renders broken here.
    private const string CLAUDE_DESIGN_CANVAS_SUFFIX = '.dc.html';

    public function __construct(
        private ProjectRepository $projects,
        private ArtifactRepository $artifacts,
        private ArtifactBlobRepository $artifactBlobs,
        private WorkerRunRepository $workerRuns,
        private FeatureFlagService $featureFlags,
        private ArtifactCardLinksInterface $cardLinks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(StoreArtifactVersionCommand $command): ArtifactVersion
    {
        // The lookup is owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner)
            ?? throw new DomainErrors(['project' => 'artifact.error.project_not_found']);

        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_ENABLED)) {
            throw new DomainErrors(['artifacts' => 'artifact.error.switched_off']);
        }

        $session = trim($command->sessionId ?? '');
        if ('' === $session) {
            $session = null;
        }
        if (null !== $session && \strlen($session) > self::MAX_SESSION_LENGTH) {
            throw new DomainErrors(['session' => 'artifact.error.session_too_long']);
        }

        if (null !== $session && Uuid::isValid($session) && null !== $this->workerRuns->findWorkerOfSession($project, Uuid::fromString($session))) {
            throw new DomainErrors(['session' => 'artifact.error.worker_refused']);
        }

        $errors = [];

        $existing = null;
        if (null !== $command->artifactId) {
            $existing = $this->artifacts->findOneByIdInProject($command->artifactId, $project);
            if (null === $existing) {
                $errors['artifactId'] = 'artifact.error.artifact_not_found';
            }
        } else {
            $name = trim($command->name ?? '');
            if ('' === $name) {
                $errors['name'] = 'artifact.error.name_blank';
            } elseif (mb_strlen($name) > Artifact::MAX_NAME_LENGTH) {
                $errors['name'] = 'artifact.error.name_too_long';
            }
        }

        $notes = trim($command->notes ?? '');
        if ('' === $notes) {
            $notes = null;
        }

        $card = trim($command->card ?? '');
        if ('' !== $card && !$this->cardLinks->cardExists($project, $card)) {
            $errors['card'] = 'artifact.error.card_not_found';
        }

        $errors += $this->fileErrors($command->files);

        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $version = $this->em->wrapInTransaction(function () use ($command, $project, $existing, $session, $card, $notes): ArtifactVersion {
            if (null !== $existing) {
                // The lock makes two stores at once take turns. No refresh: Doctrine cannot refresh the readonly
                // properties, and the lazy version list loads after the lock, so the next number is current.
                $this->em->lock($existing, LockMode::PESSIMISTIC_WRITE);
                $artifact = $existing;
            } else {
                $artifact = new Artifact($project, trim($command->name ?? ''), $command->owner, $command->sourceLink);
                $this->em->persist($artifact);
            }

            $version = $artifact->addVersion($command->owner, $session, $notes);
            foreach ($command->files as $file) {
                $version->addFile($file->path, $this->artifactBlobs->findOrCreate($project, $file->contents), $file->contentType);
            }
            $this->em->persist($version);
            $this->em->flush();
            if ('' !== $card) {
                $this->cardLinks->link($project, $card, $artifact);
                $this->em->flush();
            }

            return $version;
        });

        $this->auditor->record(
            'artifact.version_stored',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'artifactId' => (string) $version->artifact->id,
                'versionNumber' => $version->number,
                'fileCount' => \count($command->files),
                'bySession' => null !== $session,
                'linkedToCard' => '' !== $card,
            ],
            new AuditSubject('artifact', (string) $version->artifact->id),
        );

        return $version;
    }

    /**
     * @param list<ArtifactFileInput> $files
     *
     * @return array<string, string>
     */
    private function fileErrors(array $files): array
    {
        if ([] === $files) {
            return ['files' => 'artifact.error.no_files'];
        }

        $maxFiles = min($this->featureFlags->getIntValue(ArtifactInstallFlags::FLAG_MAX_FILES, ArtifactInstallFlags::DEFAULT_MAX_FILES), ArtifactInstallFlags::CEILING_MAX_FILES);
        if (\count($files) > $maxFiles) {
            return ['files' => 'artifact.error.too_many_files'];
        }

        $seen = [];
        $bytes = 0;
        foreach ($files as $file) {
            if (!self::isSafePath($file->path)) {
                return ['files' => 'artifact.error.unsafe_path'];
            }
            if (str_ends_with(strtolower($file->path), self::CLAUDE_DESIGN_CANVAS_SUFFIX)) {
                return ['files' => 'artifact.error.claude_design_canvas'];
            }
            if (isset($seen[$file->path])) {
                return ['files' => 'artifact.error.duplicate_path'];
            }
            $seen[$file->path] = true;

            if ('' === $file->contentType || \strlen($file->contentType) > self::MAX_CONTENT_TYPE_LENGTH) {
                return ['files' => 'artifact.error.bad_content_type'];
            }

            $bytes += \strlen($file->contents);
        }

        $maxBytes = min($this->featureFlags->getIntValue(ArtifactInstallFlags::FLAG_MAX_VERSION_BYTES, ArtifactInstallFlags::DEFAULT_MAX_VERSION_BYTES), ArtifactInstallFlags::CEILING_MAX_VERSION_BYTES);
        if ($bytes > $maxBytes) {
            return ['files' => 'artifact.error.too_large'];
        }

        return [];
    }

    private static function isSafePath(string $path): bool
    {
        if ('' === $path || \strlen($path) > self::MAX_PATH_LENGTH || str_starts_with($path, '/') || str_contains($path, '\\') || 1 === preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }

        return array_all(explode('/', $path), fn ($segment) => !('' === $segment || '.' === $segment || '..' === $segment));
    }
}
