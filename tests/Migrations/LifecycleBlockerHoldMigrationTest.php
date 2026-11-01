<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261101015534;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once __DIR__.'/../../migrations/Version20261101015534.php';

final class LifecycleBlockerHoldMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_open_blocker_branch_and_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-blocker-hold');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->bindTheOldCopy($project, ['epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false]);

        $this->migrate();

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($shipped['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    public function test_a_quiet_card_with_an_open_and_a_closed_blocker_is_evaluated_again_and_shows_the_blocker_hold(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-blocker-hold-card');
        $this->bindLifecycle($project);
        $this->bindTheOldCopy($project, []);
        $card = $this->card($project, 'tech-design');
        $this->em()->persist(new CardLink($this->card($project, 'next'), $card, CardLinkKind::Blocks));
        $closed = $this->card($project, 'done');
        $this->em()->persist(new CardLink($closed, $card, CardLinkKind::Blocks));
        $this->approvedTechDesign($project, $card);

        $card = $this->evaluate($card);
        self::assertNotContains('held-by-blocker', $this->markerKeys($card));
        $this->em()->getConnection()->executeStatement('UPDATE board_cards SET workflow_markers_due_at = NULL WHERE id IN (?, ?)', [(string) $card->id, (string) $closed->id]);
        $due = $this->service(CardsDueForMarkers::class);
        self::assertNotContains((string) $card->id, $due->findDueCardIds(new \DateTimeImmutable(), 10_000));

        $this->migrate();
        $this->forgetTheKeptCopy($project);

        $dueIds = $due->findDueCardIds(new \DateTimeImmutable(), 10_000);
        self::assertContains((string) $card->id, $dueIds);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT workflow_markers_due_at FROM board_cards WHERE id = ?', [(string) $closed->id]));
        self::assertContains('held-by-blocker', $this->markerKeys($this->evaluate($card)));
    }

    /** @param array<string, mixed> $kept the project values the old copy carries */
    private function bindTheOldCopy(Project $project, array $kept): void
    {
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = [...$this->row($connection, $projectId), ...$kept];
        $old['version'] = 33;
        $old['rules'] = self::withoutTheBranch($old['rules']);
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB), template_version = 33 WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        self::assertNotEquals($this->service(ShippedTemplates::class)->source('lifecycle')['rules'], $old['rules']);
        $this->forgetTheKeptCopy($project);
    }

    /** The engine reads the stored copy again, because the SQL wrote it behind the entity manager. */
    private function forgetTheKeptCopy(Project $project): void
    {
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($project->id ?? throw new \LogicException('The project is not flushed.'));
        self::assertNotNull($binding);
        $this->em()->refresh($binding);
        $this->service(ProjectTemplateCopy::class)->reset();
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = new Version20261101015534($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function approvedTechDesign(Project $project, Card $card): void
    {
        $document = new Document($project->owner, $project, 'Tech design');
        $document->addVersion('# Tech design', '<h1>Tech design</h1>');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
    }

    /** Reads the card again, because the engine stores what it writes. */
    private function evaluate(Card $card): Card
    {
        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-02 12:00:00')), $this->service(WorkflowQueuedEvaluationRepository::class), new EventDispatcher());
        $handler(new EvaluateCard((string) $card->id));
        $this->em()->flush();
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card stays.');
    }

    /** @return list<string> */
    private function markerKeys(Card $card): array
    {
        return array_map(static fn (array $marker): string => $marker['key'], $card->markers ?? []);
    }

    /**
     * @return list<mixed>
     */
    private static function withoutTheBranch(mixed $rules): array
    {
        self::assertIsArray($rules);
        $stripped = [];
        $found = false;
        foreach ($rules as $rule) {
            if (\is_array($rule) && 'tech-design-approved' === ($rule['id'] ?? null)) {
                $all = $rule['when']['all'] ?? null;
                self::assertIsArray($all);
                foreach ($all as $index => $condition) {
                    if (\is_array($condition) && \is_array($condition['any'] ?? null)) {
                        $any = $condition['any'];
                        $all[$index]['any'] = array_values(array_filter($any, static fn (mixed $branch): bool => !\is_array($branch) || !\array_key_exists('card.blocker.open', $branch)));
                        self::assertCount(\count($any) - 1, $all[$index]['any']);
                        $found = true;
                    }
                }
                $rule['when']['all'] = $all;
            }
            $stripped[] = $rule;
        }
        self::assertTrue($found);

        return $stripped;
    }

    /** @return array<string, mixed> */
    private function row(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
