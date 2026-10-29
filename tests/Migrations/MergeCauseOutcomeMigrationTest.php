<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028001629;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261028001629.php';

final class MergeCauseOutcomeMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_old_merge_causes_carry_the_merged_outcome_and_other_rows_stay(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-merge-cause');
        $card = $this->card($project, 'next');
        $events = $this->service(CardEventRepository::class);
        $at = new \DateTimeImmutable('2026-10-01 10:00:00');
        $causes = [
            'type' => ['type' => 'merged', 'pullRequest' => 12],
            'rule' => ['type' => 'workflow-rule', 'rule' => 'merged'],
            'done' => ['type' => 'workflow-rule', 'rule' => 'merged', 'outcome' => 'merged'],
            'other' => ['type' => 'workflow-rule', 'rule' => 'abandon', 'outcome' => 'abandoned'],
            'plain' => ['type' => 'checks-passed', 'pullRequest' => 3],
        ];
        foreach (array_keys($causes) as $index => $name) {
            $events->record($card, CardEventKind::Moved, Actor::System, null, ['from' => [], 'to' => [], 'cause' => $causes[$name]], $at->modify('+'.$index.' minutes'));
        }
        $this->em()->flush();
        $connection = $this->em()->getConnection();

        $this->migrate($connection);
        $this->migrate($connection);

        $expected = [
            ['type' => 'workflow-rule', 'rule' => 'merged', 'outcome' => 'merged', 'pullRequest' => 12],
            ['type' => 'workflow-rule', 'rule' => 'merged', 'outcome' => 'merged'],
            ['type' => 'workflow-rule', 'rule' => 'merged', 'outcome' => 'merged'],
            ['type' => 'workflow-rule', 'rule' => 'abandon', 'outcome' => 'abandoned'],
            ['type' => 'checks-passed', 'pullRequest' => 3],
        ];
        $rows = $connection->fetchFirstColumn("SELECT detail->'cause' FROM board_card_events WHERE card_id = ? ORDER BY occurred_at", [$card->id?->toRfc4122()]);
        self::assertCount(5, $rows);
        foreach ($rows as $index => $json) {
            self::assertIsString($json);
            $actual = json_decode($json, true);
            self::assertIsArray($actual);
            ksort($actual);
            ksort($expected[$index]);
            self::assertSame($expected[$index], $actual);
        }
    }

    private function migrate(\Doctrine\DBAL\Connection $connection): void
    {
        $migration = new Version20261028001629($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
