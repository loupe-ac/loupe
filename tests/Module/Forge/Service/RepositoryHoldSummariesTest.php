<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\Forge\Service\RepositoryChange;
use App\Module\Forge\Service\RepositoryCheckTopics;
use App\Module\Forge\Service\RepositoryHoldSummaries;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Forge\QueuedHoldReconciliations;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * The lock race itself cannot run here: the test bundle keeps each test in one
 * transaction on one connection. VerdictLockOrderTest proves with two sessions
 * that FOR UPDATE waits for FOR KEY SHARE. These tests pin the order of the
 * statements, the events and the signals.
 */
final class RepositoryHoldSummariesTest extends KernelTestCase
{
    use QueuedHoldReconciliations;

    private EntityManagerInterface $em;

    private RepositoryHoldSummaries $summaries;

    private ForgeRepositories $repositories;

    /** @var DispatchedEvents<ForgeRepositoryCheckChanged> */
    private DispatchedEvents $events;

    /** @var list<Update> */
    private array $published = [];

    private int $sequence = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));
        $this->em = $this->service(EntityManagerInterface::class);
        $this->summaries = $this->service(RepositoryHoldSummaries::class);
        $this->repositories = $this->service(ForgeRepositories::class);
        $this->events = DispatchedEvents::of(self::getContainer(), ForgeRepositoryCheckChanged::class);
    }

    public function test_a_removal_that_empties_the_summary_fires_the_event_and_signals_the_repository(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, ['no_pull_request_rule']);
        $repositoryId = (string) $repository->id;

        $this->repositories->release($project, 'github', $repository->externalId);

        self::assertSame([(string) $project->id], $this->queuedReconciliations());
        self::assertTrue($this->summaries->current($project)->isEmpty());
        self::assertSame([], $this->signalledRepositories(), 'Nothing publishes before the terminate event.');
        $this->terminate();
        self::assertSame([$repositoryId], $this->signalledRepositories());
    }

    public function test_an_installation_removal_empties_the_summary_of_each_project_and_fires_one_event_for_each(): void
    {
        $first = $this->project();
        $second = $this->project();
        $this->blockingRepository($first, ['no_pull_request_rule'], ForgeRepositorySource::Installation, '77');
        $this->blockingRepository($second, ['deletion_allowed'], ForgeRepositorySource::Installation, '77');

        $this->repositories->releaseInstallation('github', '77');

        self::assertEqualsCanonicalizing(
            [(string) $first->id, (string) $second->id],
            $this->queuedReconciliations(),
        );
        self::assertTrue($this->summaries->current($first)->isEmpty());
        self::assertTrue($this->summaries->current($second)->isEmpty());
        $this->terminate();
        self::assertCount(2, $this->signalledRepositories());
    }

    public function test_removing_a_repository_that_does_not_block_fires_no_event_but_signals(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);
        $repositoryId = (string) $repository->id;

        $this->repositories->release($project, 'github', $repository->externalId);

        self::assertSame([], $this->queuedReconciliations());
        $this->terminate();
        self::assertSame([$repositoryId], $this->signalledRepositories());
    }

    public function test_a_second_repository_that_gains_a_gap_fires_the_event(): void
    {
        $project = $this->project();
        $this->blockingRepository($project, ['no_pull_request_rule']);
        $second = $this->blockingRepository($project, []);

        $this->change($project, $second, ['deletion_allowed']);

        self::assertCount(1, $this->queuedReconciliations());
        self::assertSame(2, \count($this->summaries->current($project)->blocking));
    }

    public function test_one_of_two_blocking_repositories_that_clears_fires_the_event(): void
    {
        $project = $this->project();
        $first = $this->blockingRepository($project, ['no_pull_request_rule']);
        $this->blockingRepository($project, ['deletion_allowed']);

        $this->change($project, $first, []);

        self::assertCount(1, $this->queuedReconciliations());
        self::assertCount(1, $this->summaries->current($project)->blocking);
    }

    public function test_a_change_of_the_gap_count_fires_the_event(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, ['no_pull_request_rule']);

        $this->change($project, $repository, ['no_pull_request_rule', 'review_required']);

        self::assertCount(1, $this->queuedReconciliations());
        self::assertSame([(string) $repository->id => 2], $this->summaries->current($project)->blocking);
    }

    public function test_a_write_that_leaves_the_summary_equal_fires_no_event_but_still_signals(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, ['no_pull_request_rule']);
        $before = $this->summaries->current($project);
        self::assertFalse($before->isEmpty(), 'Guard: the write below starts from a blocking summary.');

        $this->change($project, $repository, ['review_required']);

        self::assertTrue($before->equals($this->summaries->current($project)));
        self::assertSame([], $this->queuedReconciliations());
        $this->terminate();
        self::assertSame([(string) $repository->id], $this->signalledRepositories());
    }

    public function test_a_warning_gap_does_not_count(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);

        $this->summaries->change($project, function (RepositoryChange $change) use ($repository): void {
            $check = $this->checkOf($repository);
            $check->items = [['key' => 'ci-checks', 'mark' => 'warning', 'state' => 'gap', 'gaps' => ['no_ci_check'], 'parameters' => []]];
        });

        self::assertTrue($this->summaries->current($project)->isEmpty());
        self::assertSame([], $this->queuedReconciliations());
    }

    public function test_the_event_dispatches_after_the_commit_and_a_rolled_back_write_sends_nothing(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);
        $baseline = $this->em->getConnection()->getTransactionNestingLevel();

        $this->change($project, $repository, ['no_pull_request_rule']);

        self::assertSame([], $this->events->events(), 'The write queues the reconciliation and dispatches nothing itself.');
        self::assertSame([], $this->deliverReconciliations());
        self::assertSame([$baseline], $this->events->transactionDepths());
        $this->terminate();
        self::assertCount(1, $this->published, 'Guard: a committed write does signal.');

        $this->published = [];
        $queued = \count($this->queuedReconciliations());
        $failure = $this->failureOf(fn () => $this->summaries->change($project, static function (RepositoryChange $change): void {
            $change->signal(Uuid::v7());
            throw new \RuntimeException('The write fails.');
        }));
        self::assertSame('The write fails.', $failure?->getMessage(), 'The failure reaches the caller.');
        $this->terminate();

        self::assertCount($queued, $this->queuedReconciliations());
        self::assertSame([], $this->published);
    }

    public function test_a_check_change_whose_listener_fails_keeps_its_reconciliation_until_a_retry_succeeds(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);
        $this->failNextListenerCall();

        $this->change($project, $repository, ['no_pull_request_rule']);

        self::assertCount(1, $this->deliverReconciliations(), 'Guard: the listener failed.');
        self::assertFalse($this->summaries->current($project)->isEmpty(), 'The failure leaves the stored check in place.');
        self::assertSame([], $this->deliverReconciliations(), 'The retry reconciles.');
        self::assertSame([(string) $project->id, (string) $project->id], $this->deliveredProjects());
        self::assertSame([], $this->deliverReconciliations(), 'Nothing is left to retry.');
    }

    public function test_a_removal_whose_listener_fails_keeps_its_reconciliation_until_a_retry_succeeds(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, ['no_pull_request_rule']);
        $this->failNextListenerCall();

        $this->repositories->release($project, 'github', $repository->externalId);
        $this->repositories->release($project, 'github', $repository->externalId);

        self::assertSame([(string) $project->id], $this->queuedReconciliations(), 'A repeated removal queues nothing more.');
        self::assertCount(1, $this->deliverReconciliations(), 'Guard: the listener failed.');
        self::assertTrue($this->summaries->current($project)->isEmpty(), 'The failure leaves the removal in place.');
        self::assertSame([], $this->deliverReconciliations(), 'The retry reconciles.');
        self::assertSame([(string) $project->id, (string) $project->id], $this->deliveredProjects());
    }

    public function test_the_signal_names_the_repository_on_a_private_topic_and_holds_no_content(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);

        $this->change($project, $repository, ['no_pull_request_rule']);
        $this->terminate();

        self::assertCount(1, $this->published);
        $update = $this->published[0];
        self::assertTrue($update->isPrivate());
        self::assertSame([$this->service(RepositoryCheckTopics::class)->forProject($project->id ?? throw new \LogicException())], $update->getTopics());
        self::assertSame(
            ['type' => 'forge.repository_check_changed', 'repositoryId' => (string) $repository->id, 'origin' => null],
            json_decode($update->getData(), true),
        );
    }

    public function test_the_writer_locks_the_project_row_for_update_before_it_reads_or_writes(): void
    {
        $project = $this->project();
        $repository = $this->blockingRepository($project, []);
        $holder = $this->service('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $this->change($project, $repository, ['no_pull_request_rule']);

        $statements = $this->statements($holder);
        $lock = $this->indexOf($statements, static fn (string $sql): bool => str_contains($sql, 'FROM projects') && str_ends_with($sql, 'FOR UPDATE'));
        $firstRead = $this->indexOf($statements, static fn (string $sql): bool => str_contains($sql, 'forge_repository_checks') && str_starts_with($sql, 'SELECT'));
        $write = $this->indexOf($statements, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE forge_repository_checks'));
        self::assertLessThan($firstRead, $lock, 'The lock comes before the first summary read.');
        self::assertLessThan($write, $firstRead);
    }

    public function test_a_reader_takes_a_key_share_lock_on_the_project_row(): void
    {
        $project = $this->project();
        $this->blockingRepository($project, ['no_pull_request_rule']);
        $holder = $this->service('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $summary = $this->em->wrapInTransaction(fn () => $this->summaries->currentUnderKeyShare($project));

        self::assertFalse($summary->isEmpty());
        $statements = $this->statements($holder);
        $lock = $this->indexOf($statements, static fn (string $sql): bool => str_contains($sql, 'FROM projects') && str_ends_with($sql, 'FOR KEY SHARE'));
        $read = $this->indexOf($statements, static fn (string $sql): bool => str_contains($sql, 'forge_repository_checks') && str_starts_with($sql, 'SELECT'));
        self::assertLessThan($read, $lock);
    }

    /** @param list<string> $gaps */
    private function change(Project $project, ForgeRepository $repository, array $gaps): void
    {
        $this->summaries->change($project, function (RepositoryChange $change) use ($repository, $gaps): void {
            $this->checkOf($repository)->items = [$this->item($gaps)];
            $change->signal($repository->id ?? throw new \LogicException('The repository has an id.'));
        });
    }

    private function checkOf(ForgeRepository $repository): ForgeRepositoryCheck
    {
        return $this->service(ForgeRepositoryCheckRepository::class)->findOneBy(['repository' => $repository]) ?? throw new \LogicException('The check is gone.');
    }

    /**
     * @param list<string> $gaps
     *
     * @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}
     */
    private function item(array $gaps): array
    {
        return ['key' => 'approval-gate', 'mark' => 'blocking', 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []];
    }

    /** @param list<string> $gaps */
    private function blockingRepository(Project $project, array $gaps, ForgeRepositorySource $source = ForgeRepositorySource::Hook, ?string $sourceRef = null): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.++$this->sequence, 'acme/repo-'.$this->sequence, $source, $sourceRef);
        $this->em->persist($repository);
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->items = [$this->item($gaps)];
        $this->em->persist($check);
        $this->em->flush();

        return $repository;
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Owner', email: 'summaries-'.++$this->sequence.'-'.uniqid().'@example.com', password: 'x');
        $project = new Project($owner, 'summaries-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function failNextListenerCall(): void
    {
        $failed = false;
        $this->service(EventDispatcherInterface::class)->addListener(ForgeRepositoryCheckChanged::class, static function () use (&$failed): void {
            if (!$failed) {
                $failed = true;
                throw new \RuntimeException('The listener fails.');
            }
        }, -100);
    }

    /** @return list<string> */
    private function deliveredProjects(): array
    {
        return array_map(static fn (ForgeRepositoryCheckChanged $event): string => (string) $event->projectId, $this->events->events());
    }

    private function failureOf(callable $run): ?\Throwable
    {
        try {
            $run();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** @return list<string> */
    private function signalledRepositories(): array
    {
        return array_map(static fn (Update $update): string => json_decode($update->getData(), true)['repositoryId'], $this->published);
    }

    private function terminate(): void
    {
        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    /** @return list<string> */
    private function statements(DebugDataHolder $holder): array
    {
        $statements = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = trim((string) $query['sql']);
            }
        }

        return $statements;
    }

    /** @param list<string> $statements */
    private function indexOf(array $statements, callable $match): int
    {
        foreach ($statements as $index => $sql) {
            if ($match($sql)) {
                return $index;
            }
        }

        self::fail('No statement matched in: '.implode(' | ', $statements));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertIsObject($service);

        return $service;
    }
}
