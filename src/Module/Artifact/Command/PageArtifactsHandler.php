<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Repository\ArtifactFileRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Module\Artifact\Service\ArtifactContentSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reads a page of artifacts with the parts the command asks for. Each part is one batch of
 * reads for the whole page, so the read count does not grow with the page.
 */
final readonly class PageArtifactsHandler
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private ArtifactVersionRepository $artifactVersions,
        private ArtifactFileRepository $artifactFiles,
        private ArtifactContentSigner $signer,
    ) {
    }

    /** @return Page<PagedArtifact> */
    public function __invoke(PageArtifactsCommand $command): Page
    {
        $rows = $this->artifacts->findKeysetPageByProject(
            $command->project,
            $command->includeArchived,
            $command->page->fetchSize(),
            $command->sort->newestFirst(),
            $command->page->cursor?->createdPosition(),
        );
        $page = Page::fromRows($rows, $command->page, fn (Artifact $artifact): Cursor => Cursor::afterCreated(
            $command->sort->value,
            $artifact->createdAt,
            $artifact->id ?? throw new \LogicException('A stored artifact has an id.'),
            ['includeArchived' => $command->includeArchived],
        ));

        $reads = $command->reads;
        $artifacts = $page->items;
        $latestNumbers = $reads->selectedVersion ? $this->artifactVersions->latestNumbers($artifacts) : [];
        $allVersions = $reads->versions ? $this->artifactVersions->findAllForArtifacts($artifacts) : [];
        $totals = $reads->wholeVersions ? $this->artifactFiles->totalsByVersions(array_merge([], ...array_values($allVersions))) : [];

        $latest = [];
        $filesOf = [];
        if ($reads->files || $reads->pageLink) {
            $latest = $this->artifactVersions->findLatestForArtifacts($artifacts);
            $filesOf = $this->artifactFiles->describeByVersions(array_values($latest));
        }

        return new Page(
            array_map(function (Artifact $artifact) use ($reads, $latestNumbers, $allVersions, $totals, $latest, $filesOf): PagedArtifact {
                $id = (string) $artifact->id;
                $newest = $latest[$id] ?? null;
                $files = null === $newest ? [] : ($filesOf[(string) $newest->id] ?? []);

                return new PagedArtifact(
                    $artifact,
                    $latestNumbers[$id] ?? null,
                    array_map(static fn (ArtifactVersion $version): array => self::version($version, $reads->wholeVersions ? $totals[(string) $version->id] ?? ['fileCount' => 0, 'totalBytes' => 0] : null), $allVersions[$id] ?? []),
                    $reads->files ? self::files($files, $reads->wholeFiles) : [],
                    $reads->pageLink ? $this->pageLink($newest, $files) : null,
                );
            }, $artifacts),
            $page->next,
        );
    }

    /**
     * @param array{fileCount: int, totalBytes: int}|null $total
     *
     * @return array<string, mixed>
     */
    private static function version(ArtifactVersion $version, ?array $total): array
    {
        $ref = ['number' => $version->number, 'storedAt' => $version->createdAt->format(\DateTimeInterface::ATOM)];

        return null === $total ? $ref : $ref + [
            'storedInSession' => null !== $version->storedBySession,
            'notes' => $version->notes,
            'fileCount' => $total['fileCount'],
            'totalBytes' => $total['totalBytes'],
        ];
    }

    /**
     * @param list<array{path: string, contentType: string, size: int}> $files
     *
     * @return list<array<string, mixed>>
     */
    private static function files(array $files, bool $whole): array
    {
        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return array_map(static fn (array $file): array => $whole ? $file : ['path' => $file['path']], $files);
    }

    /** @param list<array{path: string, contentType: string, size: int}> $files in path order */
    private function pageLink(?ArtifactVersion $version, array $files): ?string
    {
        $entry = ArtifactVersion::entryPath(array_column($files, 'path'));

        return null === $version || null === $entry
            ? null
            : $this->signer->linkFor($version, $entry, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
