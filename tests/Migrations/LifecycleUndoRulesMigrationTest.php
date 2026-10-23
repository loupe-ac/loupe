<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261022180917;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261022180917.php';

final class LifecycleUndoRulesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const string CLEAN = "# Design\n\nNothing to decide here.";

    public function test_it_writes_the_shipped_copy_and_a_true_state_for_each_card_with_a_past_undo_and_leaves_the_rest(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-undo');
        $this->bindLifecycle($project);
        $other = $this->workflowProject('migration-undo-other');
        $this->bindHandler()(new BindWorkflowTemplateCommand($other, 'simple', []));
        $connection = $this->em()->getConnection();

        $productPast = $this->cardWithApproval($project, 'product-design', undo: true);
        $techPast = $this->cardWithApproval($project, 'tech-design', undo: true);
        $approved = $this->cardWithApproval($project, 'product-design', undo: false);
        $changeRequestWithdrawn = $this->cardWithChangeRequestWithdrawn($project);
        $revised = $this->cardWithApproval($project, 'product-design', undo: true, revise: true);
        $archived = $this->cardWithApproval($project, 'product-design', undo: true, archive: true);
        $simple = $this->cardWithApproval($other, 'product-design', undo: true);
        $this->downgrade($connection, $project);

        $this->migrate($connection);

        self::assertSame([['product-design-undo', true]], $this->states($connection, $productPast));
        self::assertSame([['tech-design-undo', true]], $this->states($connection, $techPast));
        foreach ([$approved, $changeRequestWithdrawn, $revised, $archived, $simple] as $card) {
            self::assertSame([], $this->states($connection, $card));
        }
        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertSame($source['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [(string) $project->id]));
        self::assertStringContainsString('tech-design-undo', (string) $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [(string) $project->id]));
        $migrated = $connection->fetchAllAssociative('SELECT card_id, rule_id, truth, fingerprint FROM workflow_rule_states ORDER BY card_id, rule_id');

        $this->migrate($connection);

        self::assertSame($migrated, $connection->fetchAllAssociative('SELECT card_id, rule_id, truth, fingerprint FROM workflow_rule_states ORDER BY card_id, rule_id'));
    }

    public function test_a_past_undo_moves_no_card_at_deploy_and_the_engine_keeps_the_state_true(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-undo-deploy');
        $this->bindLifecycle($project);
        $card = $this->cardWithApproval($project, 'product-design', undo: true);
        $this->downgrade($this->em()->getConnection(), $project);

        $this->migrate($this->em()->getConnection());
        $this->service(Engine::class)->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable());

        self::assertSame('in-progress', $card->column->slug);
        $state = $this->service(WorkflowRuleStateRepository::class)->findForCard($card->id)['product-design-undo'] ?? null;
        self::assertNotNull($state);
        self::assertTrue($state->truth);
        self::assertNotNull($state->fingerprint, 'The engine writes the fingerprint on its first pass.');
    }

    /** A card in implementation with a design in review whose approval a person withdrew. */
    private function cardWithApproval(Project $project, string $tag, bool $undo, bool $revise = false, bool $archive = false): Card
    {
        $card = new Card($project, $this->column($project, 'in-progress'), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($project, ucfirst($tag), self::CLEAN, tagNames: [$tag], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $approval = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($project->owner, $document, 'approved', 1));
        if ($undo) {
            $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $project->owner, $approval->id?->toRfc4122() ?? ''));
        }
        if ($revise) {
            $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nA second round.", 'Second round.'));
        }
        if ($archive) {
            $document->archivedAt = new \DateTimeImmutable();
        }
        $this->em()->flush();

        return $card;
    }

    private function cardWithChangeRequestWithdrawn(Project $project): Card
    {
        $card = new Card($project, $this->column($project, 'in-progress'), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($project, 'Design', self::CLEAN, tagNames: ['product-design'], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $request = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($project->owner, $document, 'changes-requested', 1, 'Rework it.'));
        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $project->owner, $request->id?->toRfc4122() ?? ''));
        $this->em()->flush();

        return $card;
    }

    /** The stored copy of a project as the previous release left it: no undo rule, and an older version. */
    private function downgrade(Connection $connection, Project $project): void
    {
        $projectId = (string) $project->id;
        $definition = json_decode((string) $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);
        $definition['version'] = 16;
        $definition['rules'] = array_values(array_filter($definition['rules'], static fn (array $rule): bool => !str_ends_with($rule['id'], '-undo')));
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 16, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        $connection->executeStatement('DELETE FROM workflow_rule_states WHERE project_id = ?', [$projectId]);
    }

    /** @return list<array{string, bool}> */
    private function states(Connection $connection, Card $card): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['rule_id'], (bool) $row['truth']],
            $connection->fetchAllAssociative('SELECT rule_id, truth FROM workflow_rule_states WHERE card_id = ? ORDER BY rule_id', [(string) $card->id]),
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261022180917($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
