<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Service\ArtifactExporter;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactExporterTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_it_writes_each_artifact_with_its_versions_and_files(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'artifact-export@example.com', 'Export');
        $stranger = $this->project($em, 'artifact-export-stranger@example.com', 'Stranger');
        $png = "\x89PNG\r\n\x1a\n\x00\xff";
        $blobs = self::getContainer()->get(ArtifactBlobRepository::class);
        self::assertInstanceOf(ArtifactBlobRepository::class, $blobs);

        $artifact = new Artifact($project, 'Widget tokens', $project->owner, 'https://design.example/canvas', new \DateTimeImmutable('2026-10-01 09:00:00'));
        $first = $artifact->addVersion($project->owner, 'session-1');
        $first->addFile('index.html', $blobs->findOrCreate($project, '<p>Grüße</p>'), 'text/html');
        $second = $artifact->addVersion(notes: 'Added the logo.');
        $second->addFile('logo.png', $blobs->findOrCreate($project, $png), 'image/png');
        $second->addFile('data.bin', $blobs->findOrCreate($project, "\xff\xfe\x00"), 'application/octet-stream');
        $em->persist($artifact);
        $strangers = new Artifact($stranger, 'Not yours', $stranger->owner);
        $em->persist($strangers);
        $em->flush();
        $em->clear();

        $exporter = self::getContainer()->get(ArtifactExporter::class);
        self::assertInstanceOf(ArtifactExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('artifacts.json', $exporter->filename());
        self::assertCount(1, $rows);
        self::assertSame('Widget tokens', $rows[0]['name']);
        self::assertSame('Export', $rows[0]['project']);
        self::assertSame('https://design.example/canvas', $rows[0]['sourceLink']);
        self::assertNull($rows[0]['archivedAt']);
        self::assertSame([1, 2], array_column($rows[0]['versions'], 'number'));
        self::assertSame('session-1', $rows[0]['versions'][0]['storedBySession']);
        self::assertSame([null, 'Added the logo.'], array_column($rows[0]['versions'], 'notes'));
        self::assertSame([
            'path' => 'index.html',
            'contentType' => 'text/html',
            'size' => \strlen('<p>Grüße</p>'),
            'sha256' => hash('sha256', '<p>Grüße</p>'),
            'encoding' => 'text',
            'content' => '<p>Grüße</p>',
        ], $rows[0]['versions'][0]['files'][0]);
        $files = array_column($rows[0]['versions'][1]['files'], null, 'path');
        self::assertSame('base64', $files['logo.png']['encoding']);
        self::assertSame($png, base64_decode($files['logo.png']['content'], true));
        self::assertSame('base64', $files['data.bin']['encoding']);
        self::assertSame("\xff\xfe\x00", base64_decode($files['data.bin']['content'], true));
    }
}
