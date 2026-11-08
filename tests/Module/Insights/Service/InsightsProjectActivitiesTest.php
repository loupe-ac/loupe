<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Insights\Service\InsightsDataKind;
use App\Module\Insights\Service\InsightsProjectActivities;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InsightsProjectActivitiesTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->transport()->reset();
    }

    public function test_a_view_of_a_dormant_project_asks_for_a_wake_and_stamps_the_view(): void
    {
        $project = $this->scenarioProject('activities-dormant');

        $this->activities()->recordView((string) $project->id);

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshProjectInsights::class, $message);
        self::assertSame(InsightsDataKind::Wake->value, $message->kind);
        self::assertSame((string) $project->id, $message->projectId);
        self::assertSame(1, $this->stamped((string) $project->id, 'viewed_at'));
    }

    public function test_a_view_of_an_awake_project_asks_for_nothing(): void
    {
        $project = $this->scenarioProject('activities-awake');
        $this->activities()->recordView((string) $project->id);
        $this->transport()->reset();

        $this->activities()->recordView((string) $project->id);

        self::assertSame([], $this->transport()->getSent());
    }

    public function test_new_data_wakes_the_project_without_a_view(): void
    {
        $project = $this->scenarioProject('activities-data');
        $this->activities()->recordData((string) $project->id);
        $this->transport()->reset();

        $this->activities()->recordView((string) $project->id);

        self::assertSame([], $this->transport()->getSent());
        self::assertSame(1, $this->stamped((string) $project->id, 'data_at'));
    }

    private function stamped(string $projectId, string $column): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_project_activity WHERE project_id = :id AND '.$column.' IS NOT NULL', ['id' => $projectId]);
    }

    private function activities(): InsightsProjectActivities
    {
        $activities = self::getContainer()->get(InsightsProjectActivities::class);
        self::assertInstanceOf(InsightsProjectActivities::class, $activities);

        return $activities;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
