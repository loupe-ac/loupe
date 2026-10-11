<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Entity;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactBlob;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactVersionTest extends TestCase
{
    /** @return iterable<string, array{list<string>, ?string}> */
    public static function entries(): iterable
    {
        yield 'index.html wins' => [['a.html', 'index.html', 'b.css'], 'index.html'];
        yield 'first html file' => [['app.js', 'b.html', 'c.html'], 'b.html'];
        yield 'an upper-case extension counts' => [['a.css', 'PAGE.HTM'], 'PAGE.HTM'];
        yield 'first file when none is html' => [['a.png', 'b.css'], 'a.png'];
        yield 'a nested index.html gets no preference' => [['z.html', 'docs/index.html'], 'z.html'];
        yield 'no file' => [[], null];
    }

    /** @param list<string> $paths */
    #[DataProvider('entries')]
    public function test_it_picks_the_file_the_viewer_opens(array $paths, ?string $expected): void
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Artifacts');
        $version = new Artifact($project, 'Prototype')->addVersion();
        $blob = new ArtifactBlob($project, hash('sha256', 'x'), 'x', 1);
        foreach ($paths as $path) {
            $version->addFile($path, $blob, 'text/plain');
        }

        self::assertInstanceOf(ArtifactVersion::class, $version);
        self::assertSame($expected, $version->entryFile()?->path);
    }
}
