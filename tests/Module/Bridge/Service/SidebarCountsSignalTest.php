<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Mercure\CountChangeGate;
use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\AgentsFiguresReader;
use App\Module\Bridge\Service\SidebarCountsSignal;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\CapturesLiveUpdates;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SidebarCountsSignalTest extends KernelTestCase
{
    use BridgeScenario;
    use CapturesLiveUpdates;

    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $this->owner = $this->user($this->em(), 'sidebar-signal@example.com');
        $this->project = $this->project($this->em(), $this->owner, 'Sidebar signal');
        $this->drainLiveUpdates();
    }

    public function test_a_change_of_the_open_runs_sends_one_counts_signal_beside_the_run_signal(): void
    {
        $this->runsChanged(); // the first event of a project has no stored fingerprint and signals

        $this->seedRun($this->em(), $this->project, state: WorkerRunState::Running);
        $this->runsChanged();

        self::assertSame([WorkerRunChangedPublisher::TYPE, SidebarCountsSignal::TYPE], $this->typesSent());
    }

    public function test_a_worker_event_that_changes_no_count_sends_no_counts_signal(): void
    {
        $this->seedRun($this->em(), $this->project, state: WorkerRunState::Running);
        $this->runsChanged(); // the first event of a project has no stored fingerprint and signals

        $this->runsChanged();
        self::assertSame([WorkerRunChangedPublisher::TYPE], $this->typesSent());
        $this->runsChanged();

        self::assertSame([WorkerRunChangedPublisher::TYPE], $this->typesSent());
    }

    public function test_the_counts_signal_names_the_project_and_goes_to_its_worker_runs_topic(): void
    {
        $this->runsChanged(); // the first event of a project has no stored fingerprint and signals
        $this->seedRun($this->em(), $this->project, state: WorkerRunState::Queued);

        $this->service(WorkerRunChangedPublisher::class)->runsChanged($this->project);
        $sent = array_values(array_filter($this->drainLiveUpdates(), static fn (array $update): bool => SidebarCountsSignal::TYPE === $update['data']['type']));

        self::assertCount(1, $sent);
        self::assertSame($this->service(ProjectTopicBuilder::class)->forWorkerRuns($this->project->requireId()), $sent[0]['topic']);
        self::assertSame(['type' => SidebarCountsSignal::TYPE, 'projectId' => (string) $this->project->id, 'origin' => null], $sent[0]['data']);
    }

    public function test_a_signal_moves_the_version_and_a_quiet_event_keeps_it(): void
    {
        $this->runsChanged(); // the first event of a project has no stored fingerprint and signals
        $key = SidebarCountsSignal::versionKey($this->project);
        $gate = $this->service(CountChangeGate::class);
        $before = $gate->storedVersion($key);

        $this->runsChanged();
        self::assertSame($before, $gate->storedVersion($key));

        $this->seedRun($this->em(), $this->project, state: WorkerRunState::Running);
        $this->runsChanged();
        self::assertNotNull($gate->storedVersion($key));
        self::assertNotSame($before, $gate->storedVersion($key));
    }

    public function test_a_failing_check_still_sends_the_signal(): void
    {
        $pool = new class extends ArrayAdapter {
            #[\Override]
            public function getItem(mixed $key): CacheItem
            {
                throw new \RuntimeException('cache gone');
            }
        };
        $signal = new SidebarCountsSignal(
            new CountChangeGate($pool, new LockFactory(new InMemoryStore()), new NullLogger()),
            $this->service(AgentsFiguresReader::class),
            $this->service(WorkerRunRepository::class),
            $this->service(ProjectTopicBuilder::class),
            $this->service(LiveUpdatePublisher::class),
        );

        $signal->mark($this->project);
        $signal->check();
        $types = array_map(static fn (array $update): string => (string) $update['data']['type'], $this->drainLiveUpdates());

        self::assertSame([SidebarCountsSignal::TYPE], $types);
    }

    public function test_a_reset_forgets_the_marks(): void
    {
        $this->runsChanged(); // the first event of a project has no stored fingerprint and signals
        $this->seedRun($this->em(), $this->project, state: WorkerRunState::Running);

        $this->service(WorkerRunChangedPublisher::class)->runsChanged($this->project);
        $this->service(SidebarCountsSignal::class)->reset();

        self::assertNotContains(SidebarCountsSignal::TYPE, array_map(static fn (array $update): string => (string) $update['data']['type'], $this->drainLiveUpdates()));
    }

    private function runsChanged(): void
    {
        $this->service(WorkerRunChangedPublisher::class)->runsChanged($this->project);
        $this->sent = $this->drainLiveUpdates();
    }

    /** @var list<array{topic: string, data: array<string, mixed>}> */
    private array $sent = [];

    /** @return list<string> */
    private function typesSent(): array
    {
        return array_map(static fn (array $update): string => (string) $update['data']['type'], $this->sent);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
