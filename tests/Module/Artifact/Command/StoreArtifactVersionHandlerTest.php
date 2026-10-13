<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Command;

use App\Exception\DomainErrors;
use App\Module\Artifact\Command\ArtifactFileInput;
use App\Module\Artifact\Command\StoreArtifactVersionCommand;
use App\Module\Artifact\Command\StoreArtifactVersionHandler;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\ArtifactCardLinksInterface;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StoreArtifactVersionHandlerTest extends KernelTestCase
{
    use ArtifactScenario;

    private RecordingAuditor $audit;

    public function test_it_stores_a_new_artifact_with_its_first_version(): void
    {
        $project = $this->setUpProject('store-new@example.com');

        $version = $this->store($project, name: 'Widget tokens', sourceLink: 'https://design.example/canvas', files: [
            new ArtifactFileInput('index.html', '<h1>Hi</h1>', 'text/html'),
            new ArtifactFileInput('css/app.css', 'body{}', 'text/css'),
        ]);

        self::assertSame(1, $version->number);
        self::assertSame('Widget tokens', $version->artifact->name);
        self::assertSame('https://design.example/canvas', $version->artifact->sourceLink);
        self::assertSame($project->owner, $version->artifact->createdBy);
        self::assertSame($project->owner, $version->storedBy);
        self::assertNull($version->storedBySession);
        self::assertSame(['css/app.css', 'index.html'], $this->paths($version->files->getValues()));
        self::assertSame(1, $this->artifactRepository()->count(['project' => $project]));

        $record = $this->audit->record('artifact.version_stored');
        self::assertSame(1, $record->context['versionNumber']);
        self::assertSame(2, $record->context['fileCount']);
    }

    public function test_a_second_version_reuses_the_blobs_of_unchanged_files(): void
    {
        $project = $this->setUpProject('store-reuse@example.com');
        $first = $this->store($project, name: 'Widget tokens', files: [
            new ArtifactFileInput('index.html', 'v1', 'text/html'),
            new ArtifactFileInput('app.css', 'same', 'text/css'),
        ]);

        $second = $this->store($project, artifactId: (string) $first->artifact->id, files: [
            new ArtifactFileInput('index.html', 'v2', 'text/html'),
            new ArtifactFileInput('app.css', 'same', 'text/css'),
        ]);

        self::assertSame($first->artifact, $second->artifact);
        self::assertSame(2, $second->number);
        self::assertSame(3, $this->artifactBlobRepository()->count(['project' => $project]));
        $cssOne = $first->files->filter(static fn ($file): bool => 'app.css' === $file->path)->first();
        $cssTwo = $second->files->filter(static fn ($file): bool => 'app.css' === $file->path)->first();
        self::assertNotFalse($cssOne);
        self::assertNotFalse($cssTwo);
        self::assertSame($cssOne->blob, $cssTwo->blob);
    }

    public function test_a_session_id_that_is_no_worker_run_is_kept_on_the_version(): void
    {
        $project = $this->setUpProject('store-session@example.com');
        $session = (string) Uuid::v4();

        $version = $this->store($project, name: 'Widget tokens', sessionId: $session);

        self::assertSame($session, $version->storedBySession);
    }

    public function test_a_session_id_that_is_no_uuid_is_kept_and_not_checked_against_workers(): void
    {
        $project = $this->setUpProject('store-codex@example.com');

        $version = $this->store($project, name: 'Widget tokens', sessionId: 'codex-thread-1');

        self::assertSame('codex-thread-1', $version->storedBySession);
    }

    public function test_a_blank_session_id_means_a_person(): void
    {
        $project = $this->setUpProject('store-blank@example.com');

        $version = $this->store($project, name: 'Widget tokens', sessionId: '  ');

        self::assertNull($version->storedBySession);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function notes(): iterable
    {
        yield 'kept' => ['Tightened the header spacing.', 'Tightened the header spacing.'];
        yield 'trimmed' => ["  New pricing table.\n", 'New pricing table.'];
        yield 'blank means none' => ["  \n", null];
        yield 'absent means none' => [null, null];
    }

    #[DataProvider('notes')]
    public function test_the_notes_of_a_version_are_trimmed_and_blank_ones_are_dropped(?string $notes, ?string $expected): void
    {
        $project = $this->setUpProject('store-notes@example.com');

        $version = $this->store($project, name: 'Widget tokens', notes: $notes);

        self::assertSame($expected, $version->notes);
    }

    public function test_a_worker_session_is_refused_and_nothing_is_stored(): void
    {
        $project = $this->setUpProject('store-worker@example.com');
        $session = Uuid::v4();
        $this->workerRun($project, $session);

        $this->assertRefused($project, ['session' => 'artifact.error.worker_refused'], name: 'Widget tokens', sessionId: (string) $session);

        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_the_session_of_a_worker_run_in_another_project_does_not_refuse(): void
    {
        $project = $this->setUpProject('store-other-a@example.com');
        $other = $this->project($this->em(), 'store-other-b@example.com', 'Other');
        $session = Uuid::v4();
        $this->workerRun($other, $session);

        $version = $this->store($project, name: 'Widget tokens', sessionId: (string) $session);

        self::assertSame((string) $session, $version->storedBySession);
    }

    public function test_an_interactive_run_of_the_session_does_not_refuse(): void
    {
        $project = $this->setUpProject('store-interactive@example.com');
        $session = Uuid::v4();
        $this->workerRun($project, $session, WorkerRunKind::Interactive);

        $version = $this->store($project, name: 'Widget tokens', sessionId: (string) $session);

        self::assertSame(1, $version->number);
    }

    public function test_it_refuses_while_the_flag_is_off(): void
    {
        $project = $this->setUpProject('store-off@example.com');

        $this->assertRefused($project, ['artifacts' => 'artifact.error.switched_off'], name: 'Widget tokens', flags: [ArtifactInstallFlags::FLAG_ENABLED => false]);
    }

    public function test_it_reads_a_project_of_another_owner_as_absent(): void
    {
        $project = $this->setUpProject('store-owner-a@example.com');
        $stranger = $this->project($this->em(), 'store-owner-b@example.com', 'Stranger')->owner;

        try {
            $this->handler()(new StoreArtifactVersionCommand($stranger, (string) $project->id, null, 'X', null, null, [new ArtifactFileInput('a.html', 'a', 'text/html')]));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $error) {
            self::assertSame(['project' => 'artifact.error.project_not_found'], $error->errors);
        }
    }

    public function test_it_refuses_an_artifact_of_another_project(): void
    {
        $project = $this->setUpProject('store-cross-a@example.com');
        $other = $this->project($this->em(), 'store-cross-b@example.com', 'Cross');
        $foreign = $this->store($other, name: 'Foreign');

        $this->assertRefused($project, ['artifactId' => 'artifact.error.artifact_not_found'], artifactId: (string) $foreign->artifact->id);
        $this->assertRefused($project, ['artifactId' => 'artifact.error.artifact_not_found'], artifactId: 'not-a-uuid');
    }

    public function test_it_refuses_a_blank_name_for_a_new_artifact(): void
    {
        $project = $this->setUpProject('store-blank-name@example.com');

        $this->assertRefused($project, ['name' => 'artifact.error.name_blank'], name: '   ');
        $this->assertRefused($project, ['name' => 'artifact.error.name_blank'], name: null);
        $this->assertRefused($project, ['name' => 'artifact.error.name_too_long'], name: str_repeat('a', 256));
    }

    public function test_it_refuses_a_store_with_no_files(): void
    {
        $project = $this->setUpProject('store-empty@example.com');

        $this->assertRefused($project, ['files' => 'artifact.error.no_files'], name: 'Widget tokens', files: []);
    }

    public function test_it_refuses_more_files_than_the_flag_allows(): void
    {
        $project = $this->setUpProject('store-many@example.com');
        $files = [new ArtifactFileInput('a.html', 'a', 'text/html'), new ArtifactFileInput('b.html', 'b', 'text/html')];

        $this->assertRefused($project, ['files' => 'artifact.error.too_many_files'], name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_FILES => 1]);
        $version = $this->store($project, name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_FILES => 2]);
        self::assertCount(2, $version->files);
    }

    public function test_it_refuses_files_larger_than_the_flag_allows_in_total(): void
    {
        $project = $this->setUpProject('store-big@example.com');
        $files = [new ArtifactFileInput('a.html', '12345', 'text/html'), new ArtifactFileInput('b.html', '67890', 'text/html')];

        $this->assertRefused($project, ['files' => 'artifact.error.too_large'], name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_VERSION_BYTES => 9]);
        $version = $this->store($project, name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_VERSION_BYTES => 10]);
        self::assertCount(2, $version->files);
    }

    public function test_a_file_flag_above_the_ceiling_still_refuses_more_files_than_the_ceiling(): void
    {
        $project = $this->setUpProject('store-many-ceiling@example.com');
        $files = array_map(static fn (int $i) => new ArtifactFileInput("f{$i}.html", 'x', 'text/html'), range(0, ArtifactInstallFlags::CEILING_MAX_FILES));

        $this->assertRefused($project, ['files' => 'artifact.error.too_many_files'], name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_FILES => 1000]);
    }

    public function test_a_size_flag_above_the_ceiling_still_refuses_a_version_larger_than_the_ceiling(): void
    {
        $project = $this->setUpProject('store-big-ceiling@example.com');
        $files = [new ArtifactFileInput('a.bin', str_repeat('x', ArtifactInstallFlags::CEILING_MAX_VERSION_BYTES + 1), 'application/octet-stream')];

        $this->assertRefused($project, ['files' => 'artifact.error.too_large'], name: 'Widget tokens', files: $files, flags: [ArtifactInstallFlags::FLAG_MAX_VERSION_BYTES => 100 * 1024 * 1024]);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'leading slash' => ['/index.html'];
        yield 'parent segment' => ['../index.html'];
        yield 'inner parent segment' => ['a/../index.html'];
        yield 'dot segment' => ['./index.html'];
        yield 'empty segment' => ['a//index.html'];
        yield 'trailing slash' => ['a/'];
        yield 'backslash' => ['a\\index.html'];
        yield 'control character' => ["a\x00.html"];
        yield 'too long' => [str_repeat('a', 1025)];
    }

    #[DataProvider('unsafePaths')]
    public function test_it_refuses_an_unsafe_path(string $path): void
    {
        $project = $this->setUpProject('store-path-'.md5($path).'@example.com');

        $this->assertRefused($project, ['files' => 'artifact.error.unsafe_path'], name: 'Widget tokens', files: [new ArtifactFileInput($path, 'a', 'text/html')]);
    }

    #[TestWith(['Main.dc.html'])]
    #[TestWith(['design/Main.DC.HTML'])]
    public function test_it_refuses_a_claude_design_canvas(string $path): void
    {
        $project = $this->setUpProject('store-canvas-'.md5($path).'@example.com');

        $this->assertRefused($project, ['files' => 'artifact.error.claude_design_canvas'], name: 'Widget tokens', files: [
            new ArtifactFileInput('index.html', 'a', 'text/html'),
            new ArtifactFileInput($path, 'b', 'text/html'),
        ]);
    }

    public function test_it_refuses_a_duplicate_path(): void
    {
        $project = $this->setUpProject('store-dup@example.com');

        $this->assertRefused($project, ['files' => 'artifact.error.duplicate_path'], name: 'Widget tokens', files: [
            new ArtifactFileInput('a.html', 'a', 'text/html'),
            new ArtifactFileInput('a.html', 'b', 'text/html'),
        ]);
    }

    public function test_it_refuses_a_blank_content_type(): void
    {
        $project = $this->setUpProject('store-type@example.com');

        $this->assertRefused($project, ['files' => 'artifact.error.bad_content_type'], name: 'Widget tokens', files: [new ArtifactFileInput('a.html', 'a', '')]);
    }

    /** @param non-empty-string $email */
    private function setUpProject(string $email): Project
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        return $this->project($this->em(), $email, 'Artifacts');
    }

    /**
     * @param list<ArtifactFileInput>|null $files
     * @param array<string, bool|int>      $flags
     */
    private function store(
        Project $project,
        ?string $name = null,
        ?string $artifactId = null,
        ?string $sourceLink = null,
        ?string $sessionId = null,
        ?array $files = null,
        array $flags = [],
        ?string $notes = null,
    ): ArtifactVersion {
        return $this->handler($flags)(new StoreArtifactVersionCommand(
            $project->owner,
            (string) $project->id,
            $artifactId,
            $name,
            $sourceLink,
            $sessionId,
            $files ?? [new ArtifactFileInput('index.html', 'hello', 'text/html')],
            notes: $notes,
        ));
    }

    /**
     * @param array<string, string>        $expected
     * @param list<ArtifactFileInput>|null $files
     * @param array<string, bool|int>      $flags
     */
    private function assertRefused(
        Project $project,
        array $expected,
        ?string $name = null,
        ?string $artifactId = null,
        ?string $sessionId = null,
        ?array $files = null,
        array $flags = [],
    ): void {
        try {
            $this->store($project, $name, $artifactId, null, $sessionId, $files, $flags);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $error) {
            self::assertSame($expected, $error->errors);
        }
    }

    /** @param array<string, bool|int> $flags */
    private function handler(array $flags = []): StoreArtifactVersionHandler
    {
        $flags += [
            ArtifactInstallFlags::FLAG_ENABLED => true,
            ArtifactInstallFlags::FLAG_MAX_VERSION_BYTES => ArtifactInstallFlags::DEFAULT_MAX_VERSION_BYTES,
            ArtifactInstallFlags::FLAG_MAX_FILES => ArtifactInstallFlags::DEFAULT_MAX_FILES,
        ];

        return new StoreArtifactVersionHandler(
            self::getContainer()->get(ProjectRepository::class),
            self::getContainer()->get(ArtifactRepository::class),
            self::getContainer()->get(ArtifactBlobRepository::class),
            self::getContainer()->get(WorkerRunRepository::class),
            FeatureFlags::service($flags),
            self::getContainer()->get(ArtifactCardLinksInterface::class),
            $this->em(),
            $this->audit->auditor,
        );
    }

    /**
     * @param list<ArtifactFile> $files
     *
     * @return list<string>
     */
    private function paths(array $files): array
    {
        $paths = array_values(array_map(static fn ($file): string => $file->path, $files));
        sort($paths);

        return $paths;
    }

    private function workerRun(Project $project, Uuid $session, WorkerRunKind $kind = WorkerRunKind::Worker): void
    {
        $em = $this->em();
        $em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: WorkerRunState::Succeeded,
            sessionId: $session,
            receivedAt: new \DateTimeImmutable(),
            kind: $kind,
        ));
        $em->flush();
    }

    private function artifactRepository(): ArtifactRepository
    {
        return static::getContainer()->get(ArtifactRepository::class);
    }

    private function artifactBlobRepository(): ArtifactBlobRepository
    {
        return static::getContainer()->get(ArtifactBlobRepository::class);
    }
}
