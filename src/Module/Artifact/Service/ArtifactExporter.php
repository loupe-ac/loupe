<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Repository\ArtifactRepository;

/**
 * The artifacts in the account data export. It reads no feature flag, because
 * artifacts stored while the library was on stay the account's data after it
 * goes off. A text file is written as a string and any other file as base64.
 */
final readonly class ArtifactExporter implements UserDataExporterInterface
{
    public function __construct(
        private ArtifactRepository $artifacts,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'artifacts.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->artifacts->findByProjectOwner($user) as $artifact) {
            yield [
                'id' => (string) $artifact->id,
                'project' => $artifact->project->name,
                'name' => $artifact->name,
                'sourceLink' => $artifact->sourceLink,
                'createdAt' => $artifact->createdAt->format(\DateTimeInterface::ATOM),
                'archivedAt' => $artifact->archivedAt?->format(\DateTimeInterface::ATOM),
                'versions' => array_values(array_map(
                    fn (ArtifactVersion $version): array => [
                        'number' => $version->number,
                        'storedBySession' => $version->storedBySession,
                        'createdAt' => $version->createdAt->format(\DateTimeInterface::ATOM),
                        'files' => array_values(array_map($this->file(...), $version->files->toArray())),
                    ],
                    $artifact->versions->toArray(),
                )),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function file(ArtifactFile $file): array
    {
        $contents = $file->blob->contents();
        $isText = !str_starts_with($file->contentType, 'image/') && mb_check_encoding($contents, 'UTF-8');

        return [
            'path' => $file->path,
            'contentType' => $file->contentType,
            'size' => $file->blob->size,
            'sha256' => $file->blob->sha256,
            'encoding' => $isText ? 'text' : 'base64',
            'content' => $isText ? $contents : base64_encode($contents),
        ];
    }
}
