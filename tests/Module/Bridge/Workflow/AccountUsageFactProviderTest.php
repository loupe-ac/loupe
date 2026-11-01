<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\NamedAccountReading;
use App\Module\Bridge\Workflow\AccountUsageFactProvider;
use App\Module\Bridge\Workflow\AccountUsageFacts;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AccountUsageFactProviderTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_facts_hold_the_latest_reading_of_each_window_of_the_named_bridges_of_the_project_owner(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'usage-provider-owner@example.com');
        $project = $this->project($em, $owner, 'Usage Provider');
        $named = $this->seedBridge($em, $owner);
        $named->name = 'mac';
        $unnamed = $this->seedBridge($em, $owner);
        $stranger = $this->seedBridge($em, $this->user($em, 'usage-provider-stranger@example.com'));
        $stranger->name = 'mac';
        $em->flush();
        $this->seedAccountReading($em, $named, '2026-10-07 12:00:00', window: 'week', usedPercent: 10.0);
        $this->seedAccountReading($em, $named, '2026-10-07 12:05:00', window: 'week', usedPercent: 55.5);
        $this->seedAccountReading($em, $named, '2026-10-07 12:00:00', window: 'session', usedPercent: null, resetsAt: '2026-10-07 16:00:00');
        $this->seedAccountReading($em, $unnamed, '2026-10-07 12:00:00', usedPercent: 99.0);
        $this->seedAccountReading($em, $stranger, '2026-10-07 12:00:00', usedPercent: 98.0);

        $facts = $this->build($project);

        $readings = array_map(
            static fn (NamedAccountReading $reading): array => [$reading->bridgeName, $reading->account, $reading->window, $reading->usedPercent, $reading->resetsAt->format('Y-m-d H:i:s')],
            $facts->readings,
        );
        sort($readings);
        self::assertSame([
            ['mac', 'work', 'session', null, '2026-10-07 16:00:00'],
            ['mac', 'work', 'week', 55.5, '2026-10-07 17:00:00'],
        ], $readings);
    }

    public function test_the_fingerprint_changes_with_the_use_and_with_the_reset_but_not_with_the_read_time(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'usage-provider-fingerprint@example.com');
        $project = $this->project($em, $owner, 'Usage Fingerprint');
        $bridge = $this->seedBridge($em, $owner);
        $bridge->name = 'mac';
        $em->flush();
        $provider = $this->provider();

        $empty = $provider->fingerprint($this->build($project));
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:00:00', usedPercent: 40.0);
        $first = $provider->fingerprint($this->build($project));
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:10:00', usedPercent: 40.0);
        $later = $provider->fingerprint($this->build($project));
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:20:00', usedPercent: 41.0);
        $moved = $provider->fingerprint($this->build($project));
        $this->seedAccountReading($em, $bridge, '2026-10-07 12:30:00', usedPercent: 41.0, resetsAt: '2026-10-08 17:00:00');
        $reset = $provider->fingerprint($this->build($project));

        self::assertNotSame($empty, $first, 'A reading where there was none counts.');
        self::assertSame($first, $later, 'Only the compared values count.');
        self::assertNotSame($first, $moved);
        self::assertNotSame($moved, $reset);
    }

    public function test_the_provider_is_on_and_belongs_to_the_bridge_module(): void
    {
        self::bootKernel();

        self::assertTrue($this->provider()->isOn());
        self::assertSame(AccountUsageFacts::class, $this->provider()->factsClass());
        self::assertSame('workflow.source.bridge', $this->provider()->source());
    }

    private function build(Project $project): AccountUsageFacts
    {
        $id = $project->id ?? throw new \LogicException('A stored project has an id.');
        $facts = $this->provider()->build(new CardSnapshot(Uuid::v7(), $id, 1, 'feature', new ColumnRef(Uuid::v7(), false, false), null, null, null));
        self::assertInstanceOf(AccountUsageFacts::class, $facts);

        return $facts;
    }

    private function provider(): AccountUsageFactProvider
    {
        $provider = self::getContainer()->get(AccountUsageFactProvider::class);
        self::assertInstanceOf(AccountUsageFactProvider::class, $provider);

        return $provider;
    }
}
