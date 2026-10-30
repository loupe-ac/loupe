<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Mcp\ArtifactArchiveTool;
use App\Module\Artifact\Mcp\ArtifactGetTool;
use App\Module\Artifact\Mcp\ArtifactListTool;
use App\Module\Artifact\Mcp\ArtifactReadTool;
use App\Module\Artifact\Mcp\ArtifactUnarchiveTool;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Mcp\Capability\Registry;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactToolsTest extends KernelTestCase
{
    use ArtifactScenario;
    use McpTokenScenario;

    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00";

    private RecordingAuditor $audit;

    /** @return iterable<string, array{class-string}> */
    public static function tools(): iterable
    {
        yield 'artifact_list' => [ArtifactListTool::class];
        yield 'artifact_get' => [ArtifactGetTool::class];
        yield 'artifact_read' => [ArtifactReadTool::class];
        yield 'artifact_archive' => [ArtifactArchiveTool::class];
        yield 'artifact_unarchive' => [ArtifactUnarchiveTool::class];
    }

    public function test_list_returns_newest_first_with_the_latest_version_and_hides_archived_ones(): void
    {
        $project = $this->switchedOnProject('list@example.com');
        $first = $this->artifact($project, 'First', [['index.html', 'a', 'text/html']]);
        $second = $this->artifact($project, 'Second', [['index.html', 'b', 'text/html']]);
        $second->addVersion()->addFile('index.html', $this->blob($project, 'c'), 'text/html');
        $archived = $this->artifact($project, 'Gone', [['index.html', 'd', 'text/html']]);
        $archived->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $result = ($this->tool(ArtifactListTool::class))();

        self::assertSame(2, $result['total']);
        self::assertFalse($result['hasMore']);
        $names = array_column($result['artifacts'], 'name');
        self::assertEqualsCanonicalizing(['First', 'Second'], $names);
        $byName = array_column($result['artifacts'], null, 'name');
        self::assertSame(2, $byName['Second']['latestVersion']);
        self::assertSame(1, $byName['First']['latestVersion']);
        self::assertSame(\sprintf('/projects/%s/artifacts/%s', $project->id, $first->id), $byName['First']['path']);

        $all = ($this->tool(ArtifactListTool::class))(includeArchived: true);
        self::assertSame(3, $all['total']);
        self::assertTrue(array_column($all['artifacts'], null, 'name')['Gone']['archived']);
    }

    public function test_list_pages_and_clamps_out_of_range_arguments(): void
    {
        $project = $this->switchedOnProject('paging@example.com');
        foreach (['A', 'B', 'C'] as $name) {
            $this->artifact($project, $name, [['index.html', $name, 'text/html']]);
        }

        $page = ($this->tool(ArtifactListTool::class))(page: 1, perPage: 2);
        self::assertCount(2, $page['artifacts']);
        self::assertTrue($page['hasMore']);

        $next = ($this->tool(ArtifactListTool::class))(page: 2, perPage: 2);
        self::assertCount(1, $next['artifacts']);
        self::assertFalse($next['hasMore']);

        $clamped = ($this->tool(ArtifactListTool::class))(page: -5, perPage: 0);
        self::assertSame(1, $clamped['page']);
        self::assertSame(1, $clamped['perPage']);
    }

    public function test_get_describes_every_version_and_lists_the_files_of_the_latest(): void
    {
        $project = $this->switchedOnProject('get@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'hello', 'text/html'], ['app.css', 'x', 'text/css']], 'https://design.example/c');
        $artifact->addVersion(storedBySession: 'session-1', notes: 'Shorter greeting.')->addFile('index.html', $this->blob($project, 'hello two'), 'text/html');
        $this->em()->flush();

        $result = ($this->tool(ArtifactGetTool::class))((string) $artifact->id);

        self::assertSame('Widget', $result['name']);
        self::assertSame('https://design.example/c', $result['sourceLink']);
        self::assertFalse($result['archived']);
        self::assertSame([1, 2], array_column($result['versions'], 'number'));
        self::assertSame([2, 1], array_column($result['versions'], 'fileCount'));
        self::assertSame([6, 9], array_column($result['versions'], 'totalBytes'));
        self::assertSame([false, true], array_column($result['versions'], 'storedInSession'));
        self::assertSame([null, 'Shorter greeting.'], array_column($result['versions'], 'notes'));
        self::assertSame(2, $result['selectedVersion']);
        self::assertSame([['path' => 'index.html', 'contentType' => 'text/html', 'size' => 9]], $result['files']);

        $first = ($this->tool(ArtifactGetTool::class))((string) $artifact->id, 1);
        self::assertSame(1, $first['selectedVersion']);
        self::assertSame(['app.css', 'index.html'], array_column($first['files'], 'path'));
    }

    public function test_get_returns_a_full_address_of_the_entry_page(): void
    {
        $project = $this->switchedOnProject('pagelink@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'hello', 'text/html']]);

        $result = ($this->tool(ArtifactGetTool::class))((string) $artifact->id);

        self::assertIsString($result['pageLink']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/.+index\.html$#', $result['pageLink']);
    }

    public function test_get_returns_no_page_link_for_a_version_with_no_files(): void
    {
        $project = $this->switchedOnProject('nopagelink@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);
        $artifact->addVersion();
        $this->em()->flush();

        $result = ($this->tool(ArtifactGetTool::class))((string) $artifact->id);

        self::assertNull($result['pageLink']);
    }

    public function test_get_refuses_an_unknown_version(): void
    {
        $project = $this->switchedOnProject('noversion@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('has no version 9');

        ($this->tool(ArtifactGetTool::class))((string) $artifact->id, 9);
    }

    public function test_read_returns_a_text_file_as_text_from_the_latest_version_by_default(): void
    {
        $project = $this->switchedOnProject('read-text@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', '<h1>One</h1>', 'text/html; charset=utf-8']]);
        $artifact->addVersion()->addFile('index.html', $this->blob($project, '<h1>Two — é</h1>'), 'text/html');
        $this->em()->flush();

        $latest = ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'index.html');
        $old = ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'index.html', 1);

        self::assertInstanceOf(TextContent::class, $latest);
        self::assertSame('<h1>Two — é</h1>', $latest->text);
        self::assertInstanceOf(TextContent::class, $old);
        self::assertSame('<h1>One</h1>', $old->text);
    }

    public function test_read_returns_an_image_as_image_content(): void
    {
        $project = $this->switchedOnProject('read-image@example.com');
        $artifact = $this->artifact($project, 'Shot', [['shot.png', self::PNG, 'image/png']]);

        $result = ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'shot.png');

        self::assertInstanceOf(ImageContent::class, $result);
        self::assertSame('image/png', $result->mimeType);
        self::assertSame(self::PNG, base64_decode($result->data, true));
    }

    public function test_read_refuses_other_binary_files_and_names_their_size(): void
    {
        $project = $this->switchedOnProject('read-binary@example.com');
        $artifact = $this->artifact($project, 'Font', [['font.woff2', "wOF2\x00\x01\xff\xfe", 'font/woff2']]);

        try {
            ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'font.woff2');
            self::fail('A binary file must be refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('binary file of 8 bytes (font/woff2)', $e->getMessage());
        }
    }

    public function test_read_refuses_a_binary_content_type_even_when_its_bytes_are_valid_utf8(): void
    {
        $project = $this->switchedOnProject('read-pdf@example.com');
        $artifact = $this->artifact($project, 'Spec', [['spec.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", 'application/pdf']]);

        $message = $this->refusal(fn () => ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'spec.pdf'));

        self::assertStringContainsString('binary file of 29 bytes (application/pdf)', $message);
    }

    public function test_read_returns_a_structured_text_type_as_text(): void
    {
        $project = $this->switchedOnProject('read-json@example.com');
        $artifact = $this->artifact($project, 'Data', [['data.json', '{"a":1}', 'application/vnd.api+json'], ['icon.svg', '<svg/>', 'image/svg+xml']]);

        $json = ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'data.json');
        $svg = ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'icon.svg');

        self::assertInstanceOf(TextContent::class, $json);
        self::assertSame('{"a":1}', $json->text);
        self::assertInstanceOf(TextContent::class, $svg);
    }

    public function test_read_refuses_a_missing_path(): void
    {
        $project = $this->switchedOnProject('read-missing@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('has no file "nope.html"');

        ($this->tool(ArtifactReadTool::class))((string) $artifact->id, 'nope.html');
    }

    public function test_archive_and_unarchive_flip_the_state_and_audit_only_a_change(): void
    {
        $project = $this->switchedOnProject('archive@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);

        $archived = ($this->tool(ArtifactArchiveTool::class))((string) $artifact->id);
        self::assertSame(['artifactId' => (string) $artifact->id, 'archived' => true], $archived);
        $firstStamp = $artifact->archivedAt;
        self::assertNotNull($firstStamp);

        ($this->tool(ArtifactArchiveTool::class))((string) $artifact->id);
        self::assertSame($firstStamp, $artifact->archivedAt);
        self::assertCount(1, $this->audit->sink->events);

        $restored = ($this->tool(ArtifactUnarchiveTool::class))((string) $artifact->id);
        self::assertSame(['artifactId' => (string) $artifact->id, 'archived' => false], $restored);
        self::assertNull($artifact->archivedAt);

        ($this->tool(ArtifactUnarchiveTool::class))((string) $artifact->id);
        self::assertCount(2, $this->audit->sink->events);
    }

    public function test_archive_and_unarchive_read_the_stored_state_and_audit_nothing_when_another_caller_changed_it(): void
    {
        $project = $this->switchedOnProject('archive-race@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);
        $connection = $this->em()->getConnection();

        $connection->executeStatement('UPDATE artifacts SET archived_at = NOW() WHERE id = ?', [$artifact->id], ['uuid']);
        ($this->tool(ArtifactArchiveTool::class))((string) $artifact->id);
        self::assertNotNull($artifact->archivedAt);

        $connection->executeStatement('UPDATE artifacts SET archived_at = NULL WHERE id = ?', [$artifact->id], ['uuid']);
        ($this->tool(ArtifactUnarchiveTool::class))((string) $artifact->id);
        self::assertNull($artifact->archivedAt);

        self::assertCount(0, $this->audit->sink->events);
    }

    public function test_unarchive_clears_a_stored_archive_that_the_loaded_entity_does_not_show(): void
    {
        $project = $this->switchedOnProject('unarchive-stale@example.com');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);
        $connection = $this->em()->getConnection();

        $connection->executeStatement('UPDATE artifacts SET archived_at = NOW() WHERE id = ?', [$artifact->id], ['uuid']);
        self::assertNull($artifact->archivedAt);

        $restored = ($this->tool(ArtifactUnarchiveTool::class))((string) $artifact->id);

        self::assertSame(['artifactId' => (string) $artifact->id, 'archived' => false], $restored);
        self::assertNull($connection->fetchOne('SELECT archived_at FROM artifacts WHERE id = ?', [$artifact->id], ['uuid']));
        self::assertCount(1, $this->audit->sink->events);
    }

    public function test_an_artifact_of_another_project_reads_as_not_found_for_every_tool(): void
    {
        $this->switchedOnProject('owner-a@example.com');
        $other = $this->project($this->em(), 'owner-b@example.com', 'Other');
        $foreign = $this->artifact($other, 'Secret', [['index.html', 'a', 'text/html']]);
        $mine = $this->project($this->em(), 'owner-c@example.com', 'Mine');
        $this->actAsMcpTokenBoundTo($mine);

        $id = (string) $foreign->id;
        $calls = [
            fn () => ($this->tool(ArtifactGetTool::class))($id),
            fn () => ($this->tool(ArtifactReadTool::class))($id, 'index.html'),
            fn () => ($this->tool(ArtifactArchiveTool::class))($id),
            fn () => ($this->tool(ArtifactUnarchiveTool::class))($id),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('A foreign artifact must not be reachable.');
            } catch (ToolCallException $e) {
                self::assertSame(\sprintf('Artifact "%s" not found or not accessible.', $id), $e->getMessage());
            }
        }

        self::assertNull($foreign->archivedAt);
    }

    public function test_an_unknown_id_and_a_malformed_id_read_as_not_found(): void
    {
        $this->switchedOnProject('unknown@example.com');

        foreach (['0198a5b2-0000-7000-8000-000000000000', 'not-a-uuid'] as $id) {
            try {
                ($this->tool(ArtifactGetTool::class))($id);
                self::fail('An unknown id must be refused.');
            } catch (ToolCallException $e) {
                self::assertSame(\sprintf('Artifact "%s" not found or not accessible.', $id), $e->getMessage());
            }
        }
    }

    /** @param class-string $class */
    #[DataProvider('tools')]
    public function test_every_tool_refuses_while_the_flag_is_off(string $class): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), 'off-'.md5($class).'@example.com', 'Off');
        $artifact = $this->artifact($project, 'Widget', [['index.html', 'a', 'text/html']]);
        $this->actAsMcpTokenBoundTo($project);

        $id = (string) $artifact->id;
        $call = match ($class) {
            ArtifactListTool::class => fn () => ($this->tool(ArtifactListTool::class))(),
            ArtifactGetTool::class => fn () => ($this->tool(ArtifactGetTool::class))($id),
            ArtifactReadTool::class => fn () => ($this->tool(ArtifactReadTool::class))($id, 'index.html'),
            ArtifactArchiveTool::class => fn () => ($this->tool(ArtifactArchiveTool::class))($id),
            default => fn () => ($this->tool(ArtifactUnarchiveTool::class))($id),
        };

        self::assertSame('Artifacts are switched off on this instance.', $this->refusal($call));
        self::assertNull($artifact->archivedAt);
    }

    public function test_the_tools_are_registered_gated_and_advertised_only_while_the_flag_is_on(): void
    {
        self::bootKernel();
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));
        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);

        foreach (self::tools() as [$class]) {
            $tool = self::getContainer()->get($class);
            self::assertInstanceOf(FlagGatedToolInterface::class, $tool);
            self::assertSame(ArtifactInstallFlags::FLAG_ENABLED, $tool->requiredFlag());
            self::assertTrue($registry->hasTool($tool->gatedToolName()));
        }

        $names = fn (): array => array_column($this->advertised()->enabled(), 'name');
        self::assertNotContains('artifact_list', $names());

        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);
        self::ensureKernelShutdown();
        self::bootKernel();
        $order = array_flip($names());
        self::assertSame($order['artifact_list'] + 1, $order['artifact_get']);
        self::assertSame($order['artifact_get'] + 1, $order['artifact_read']);
        self::assertSame($order['artifact_read'] + 1, $order['artifact_archive']);
        self::assertSame($order['artifact_archive'] + 1, $order['artifact_unarchive']);
        self::assertSame($order['card_run_close'] + 1, $order['artifact_list']);
    }

    public function test_read_requires_a_path_and_get_requires_only_the_id(): void
    {
        self::bootKernel();
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));
        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);

        self::assertSame(['artifactId', 'path'], $registry->getTool('artifact_read')->tool->inputSchema['required']);
        self::assertSame(['artifactId'], $registry->getTool('artifact_get')->tool->inputSchema['required']);
        self::assertArrayNotHasKey('required', $registry->getTool('artifact_list')->tool->inputSchema);
    }

    private function advertised(): AdvertisedTools
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        return $advertised;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): object
    {
        $tool = self::getContainer()->get($class);
        self::assertInstanceOf($class, $tool);

        return $tool;
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('The tool must refuse.');
    }

    /** @param non-empty-string $email */
    private function switchedOnProject(string $email): Project
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $project = $this->project($this->em(), $email, 'Artifacts');
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);
        $this->actAsMcpTokenBoundTo($project);

        return $project;
    }

    private function blob(Project $project, string $contents): \App\Module\Artifact\Entity\ArtifactBlob
    {
        $blobs = self::getContainer()->get(ArtifactBlobRepository::class);
        self::assertInstanceOf(ArtifactBlobRepository::class, $blobs);

        return $blobs->findOrCreate($project, $contents);
    }

    /** @param list<array{string, string, string}> $files path, contents, content type */
    private function artifact(Project $project, string $name, array $files, ?string $sourceLink = null): Artifact
    {
        $artifact = new Artifact($project, $name, $project->owner, $sourceLink);
        $version = $artifact->addVersion($project->owner);
        foreach ($files as [$path, $contents, $type]) {
            $version->addFile($path, $this->blob($project, $contents), $type);
        }
        $this->em()->persist($artifact);
        $this->em()->flush();

        return $artifact;
    }
}
