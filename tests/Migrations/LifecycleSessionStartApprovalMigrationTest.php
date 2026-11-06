<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261106133000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261106133000.php';

final class LifecycleSessionStartApprovalMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const string RULE = 'tech-design-session-presence';

    public function test_a_lifecycle_copy_gets_the_shipped_definition_and_keeps_its_two_project_keys(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-session-start');
        $this->bindLifecycle($lifecycle);
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings
            SET template_version = 41,
                definition = jsonb_set(jsonb_set(jsonb_set(definition, '{version}', '41'), '{epicBranch}', '\"feature/epic-{number}\"'), '{approvalCoversConflictResolution}', 'false')
            WHERE project_id = ?",
            [$lifecycleId],
        );

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $stored = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]);
        self::assertIsString($stored);
        $after = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($after);
        self::assertSame($shipped['version'], $after['version']);
        self::assertSame($shipped['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
        self::assertSame('feature/epic-{number}', $after['epicBranch']);
        self::assertFalse($after['approvalCoversConflictResolution']);
        self::assertContains(self::RULE, array_column($after['rules'], 'id'));
        self::assertContains('session-start-approved', array_column($after['values'], 'key'));
    }

    public function test_a_card_whose_switch_is_on_gets_a_true_state_for_the_presence_rule_and_the_others_get_none(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-session-start-cards');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-session-start-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $on = $this->card($lifecycle, 'next');
        $off = $this->card($lifecycle, 'next');
        $unset = $this->card($lifecycle, 'next');
        $onSimple = $this->card($simple, 'next');
        $this->em()->persist(new CardValue($on, 'tech-design-interactive', true, Actor::Human));
        $this->em()->persist(new CardValue($off, 'tech-design-interactive', false, Actor::Human));
        $this->em()->persist(new CardValue($onSimple, 'tech-design-interactive', true, Actor::Human));
        $this->em()->flush();
        $connection = $this->em()->getConnection();

        $this->migrate($connection);
        $this->migrate($connection);

        $rows = $connection->fetchAllAssociative('SELECT card_id, truth FROM workflow_rule_states WHERE rule_id = ?', [self::RULE]);
        $byCard = array_column($rows, 'truth', 'card_id');
        self::assertTrue($byCard[self::idOf($on)] ?? false);
        self::assertArrayNotHasKey(self::idOf($off), $byCard);
        self::assertArrayNotHasKey(self::idOf($unset), $byCard);
        self::assertArrayNotHasKey(self::idOf($onSimple), $byCard);
    }

    private static function idOf(Card $card): string
    {
        return ($card->id ?? throw new \LogicException('The card is not flushed.'))->toRfc4122();
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261106133000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
