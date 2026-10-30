<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Exception\DomainErrors;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Module\Artifact\Service\ArtifactContentSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @phpstan-type ArtifactPayload array{artifactId: string, name: string, archived: bool, archivedAt: ?string, sourceLink: ?string, createdAt: string, path: string, versions: list<array{number: int, storedAt: string, storedInSession: bool, notes: ?string, fileCount: int, totalBytes: int}>, selectedVersion: ?int, pageLink: ?string, files: list<array{path: string, contentType: string, size: int}>}
 */
final readonly class DescribeArtifactHandler
{
    public function __construct(
        private ArtifactVersionRepository $artifactVersions,
        private ArtifactContentSigner $signer,
    ) {
    }

    /** @return ArtifactPayload */
    public function __invoke(DescribeArtifactCommand $command): array
    {
        $artifact = $command->artifact;
        $summaries = $this->artifactVersions->summaries($artifact);

        $selected = null;
        foreach ($summaries as $summary) {
            if (null === $command->versionNumber || $summary['version']->number === $command->versionNumber) {
                $selected = $summary['version'];
            }
        }
        if (null !== $command->versionNumber && null === $selected) {
            throw new DomainErrors(['versionNumber' => 'artifact.error.version_not_found']);
        }

        return [
            'artifactId' => (string) $artifact->id,
            'name' => $artifact->name,
            'archived' => null !== $artifact->archivedAt,
            'archivedAt' => $artifact->archivedAt?->format(\DateTimeInterface::ATOM),
            'sourceLink' => $artifact->sourceLink,
            'createdAt' => $artifact->createdAt->format(\DateTimeInterface::ATOM),
            'path' => \sprintf('/projects/%s/artifacts/%s', $artifact->project->id, $artifact->id),
            'versions' => array_map(
                static fn (array $summary): array => [
                    'number' => $summary['version']->number,
                    'storedAt' => $summary['version']->createdAt->format(\DateTimeInterface::ATOM),
                    'storedInSession' => null !== $summary['version']->storedBySession,
                    'notes' => $summary['version']->notes,
                    'fileCount' => $summary['fileCount'],
                    'totalBytes' => $summary['totalBytes'],
                ],
                $summaries,
            ),
            'selectedVersion' => $selected?->number,
            'pageLink' => $this->pageLink($selected),
            'files' => null === $selected ? [] : $this->files($selected),
        ];
    }

    /** A one-hour full address of the entry page, or null when the version has none. */
    private function pageLink(?ArtifactVersion $version): ?string
    {
        $entry = $version?->entryFile();

        return null === $version || null === $entry
            ? null
            : $this->signer->linkFor($version, $entry->path, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @return list<array{path: string, contentType: string, size: int}> */
    private function files(ArtifactVersion $version): array
    {
        $files = $version->files->toArray();
        usort($files, static fn (ArtifactFile $left, ArtifactFile $right): int => $left->path <=> $right->path);

        return array_map(
            static fn (ArtifactFile $file): array => [
                'path' => $file->path,
                'contentType' => $file->contentType,
                'size' => $file->blob->size,
            ],
            $files,
        );
    }
}
