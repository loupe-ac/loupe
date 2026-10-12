<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardArtifactToolsTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardCreateTool $create;
    private CardUpdateTool $update;
    private CardGetTool $get;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $create = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $create);
        $this->create = $create;
        $update = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $update);
        $this->update = $update;
        $get = self::getContainer()->get(CardGetTool::class);
        self::assertInstanceOf(CardGetTool::class, $get);
        $this->get = $get;
    }

    public function test_card_create_links_the_artifacts_and_card_get_reads_them_back(): void
    {
        $project = $this->makeProject('artifact-create');
        $artifact = $this->artifact($project, 'Widget tokens', versions: 3);
        $this->actAsMcpTokenBoundTo($project);

        $created = ($this->create)('Prototype', 'Body', 'feature', artifactIds: [(string) $artifact->id]);
        $read = ($this->get)($created['cardId']);

        foreach ([$created, $read] as $card) {
            self::assertSame([[
                'artifactId' => (string) $artifact->id,
                'name' => 'Widget tokens',
                'versionNumber' => 3,
                'archived' => false,
                'path' => \sprintf('/projects/%s/artifacts/%s', $project->id, $artifact->id),
            ]], $card['artifacts']);
        }
    }

    public function test_card_get_reports_an_archived_artifact_and_a_card_without_artifacts(): void
    {
        $project = $this->makeProject('artifact-archived');
        $artifact = $this->artifact($project, 'Old');
        $this->actAsMcpTokenBoundTo($project);
        $bare = ($this->create)('Bare', 'Body', 'feature');
        $linked = ($this->create)('Linked', 'Body', 'feature', artifactIds: [(string) $artifact->id]);

        $artifact->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame([], ($this->get)($bare['cardId'])['artifacts']);
        self::assertTrue(($this->get)($linked['cardId'])['artifacts'][0]['archived']);
    }

    public function test_card_update_omits_keeps_replaces_and_clears(): void
    {
        $project = $this->makeProject('artifact-update');
        $first = $this->artifact($project, 'First');
        $second = $this->artifact($project, 'Second');
        $this->actAsMcpTokenBoundTo($project);
        $card = ($this->create)('Prototype', 'Body', 'feature', artifactIds: [(string) $first->id]);

        $kept = ($this->update)($card['cardId'], title: 'Renamed');
        self::assertSame([(string) $first->id], array_column($kept['artifacts'], 'artifactId'));

        $replaced = ($this->update)($card['cardId'], artifactIds: [(string) $second->id]);
        self::assertSame([(string) $second->id], array_column($replaced['artifacts'], 'artifactId'));

        $cleared = ($this->update)($card['cardId'], artifactIds: []);
        self::assertSame([], $cleared['artifacts']);
    }

    public function test_an_artifact_of_another_project_is_refused_by_both_tools(): void
    {
        $project = $this->makeProject('artifact-mine');
        $theirs = $this->artifact($this->makeProject('artifact-theirs'), 'Theirs');
        $this->actAsMcpTokenBoundTo($project);
        $card = ($this->create)('Prototype', 'Body', 'feature');

        foreach ([
            fn (): mixed => ($this->create)('Other', 'Body', 'feature', artifactIds: [(string) $theirs->id]),
            fn (): mixed => ($this->update)($card['cardId'], artifactIds: [(string) $theirs->id]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (ToolCallException $e) {
                self::assertStringContainsString('One of those artifact ids names no artifact of this project.', $e->getMessage());
            }
        }

        self::assertSame([], ($this->get)($card['cardId'])['artifacts']);
    }

    private function artifact(Project $project, string $name, int $versions = 1): Artifact
    {
        $artifact = new Artifact($project, $name);
        for ($i = 0; $i < $versions; ++$i) {
            $this->em->persist($artifact);
            $this->em->persist($artifact->addVersion());
        }
        $this->em->flush();

        return $artifact;
    }
}
