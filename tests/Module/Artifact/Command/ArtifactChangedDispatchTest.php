<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Command;

use App\Module\Artifact\Command\ArchiveArtifactCommand;
use App\Module\Artifact\Command\ArchiveArtifactHandler;
use App\Module\Artifact\Command\ArtifactFileInput;
use App\Module\Artifact\Command\StoreArtifactVersionCommand;
use App\Module\Artifact\Command\StoreArtifactVersionHandler;
use App\Module\Artifact\Command\UnarchiveArtifactCommand;
use App\Module\Artifact\Command\UnarchiveArtifactHandler;
use App\Module\Artifact\Event\ArtifactChange;
use App\Module\Artifact\Event\ArtifactChanged;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ArtifactChangedDispatchTest extends KernelTestCase
{
    use ArtifactScenario;

    /** @var list<ArtifactChanged> */
    private array $events = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ArtifactChanged::class, function (ArtifactChanged $event): void {
            $this->events[] = $event;
        });
    }

    public function test_storing_a_version_announces_its_number(): void
    {
        $project = $this->switchedOnProject('dispatch-store@example.com');
        $handler = self::getContainer()->get(StoreArtifactVersionHandler::class);
        self::assertInstanceOf(StoreArtifactVersionHandler::class, $handler);

        $version = $handler(new StoreArtifactVersionCommand($project->owner, (string) $project->id, null, 'Tokens', null, null, [new ArtifactFileInput('index.html', 'a', 'text/html')]));
        $next = $handler(new StoreArtifactVersionCommand($project->owner, (string) $project->id, (string) $version->artifact->id, null, null, null, [new ArtifactFileInput('index.html', 'b', 'text/html')]));

        self::assertCount(2, $this->events);
        self::assertSame([ArtifactChange::Stored, 1], [$this->events[0]->change, $this->events[0]->versionNumber]);
        self::assertSame([ArtifactChange::Stored, 2], [$this->events[1]->change, $this->events[1]->versionNumber]);
        self::assertTrue($next->artifact->id?->equals($this->events[1]->artifactId));
        self::assertTrue($project->id?->equals($this->events[1]->projectId));
    }

    public function test_a_refused_store_announces_nothing(): void
    {
        $project = $this->switchedOnProject('dispatch-refused@example.com');
        $handler = self::getContainer()->get(StoreArtifactVersionHandler::class);
        self::assertInstanceOf(StoreArtifactVersionHandler::class, $handler);

        try {
            $handler(new StoreArtifactVersionCommand($project->owner, (string) $project->id, null, '', null, null, [new ArtifactFileInput('index.html', 'a', 'text/html')]));
            self::fail('Expected a refusal.');
        } catch (\App\Exception\DomainErrors) {
            self::assertSame([], $this->events);
        }
    }

    public function test_archive_and_restore_announce_once_each_and_a_repeat_announces_nothing(): void
    {
        $project = $this->switchedOnProject('dispatch-archive@example.com');
        $artifact = $this->storedArtifact($project, 'Shelf', [['index.html' => 'a']]);
        $archive = self::getContainer()->get(ArchiveArtifactHandler::class);
        $unarchive = self::getContainer()->get(UnarchiveArtifactHandler::class);
        self::assertInstanceOf(ArchiveArtifactHandler::class, $archive);
        self::assertInstanceOf(UnarchiveArtifactHandler::class, $unarchive);

        $archive(new ArchiveArtifactCommand($artifact));
        $archive(new ArchiveArtifactCommand($artifact));
        $unarchive(new UnarchiveArtifactCommand($artifact));
        $unarchive(new UnarchiveArtifactCommand($artifact));

        self::assertSame([ArtifactChange::Archived, ArtifactChange::Unarchived], array_map(static fn (ArtifactChanged $event): ArtifactChange => $event->change, $this->events));
        self::assertNull($this->events[0]->versionNumber);
    }
}
