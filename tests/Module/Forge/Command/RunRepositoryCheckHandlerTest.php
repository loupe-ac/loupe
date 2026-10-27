<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Command;

use App\Module\Account\Entity\User;
use App\Module\Forge\Command\RunRepositoryCheckCommand;
use App\Module\Forge\Command\RunRepositoryCheckHandler;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\RepositoryChecks;
use App\Module\Forge\Service\RepositoryHoldSummaries;
use App\Module\Forge\Service\RepositorySettingsReaders;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Tests\Module\Forge\FakeRepositorySettingsReader;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class RunRepositoryCheckHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-10-27 12:00:00';

    private EntityManagerInterface $em;

    private MockClock $clock;

    private FakeRepositorySettingsReader $reader;

    private RunRepositoryCheckHandler $handler;

    private RepositoryHoldSummaries $summaries;

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
        $this->events = DispatchedEvents::of(self::getContainer(), ForgeRepositoryCheckChanged::class);
        $this->clock = new MockClock(self::NOW);
        $this->reader = new FakeRepositorySettingsReader();

        $checks = $this->service(ForgeRepositoryCheckRepository::class);
        $this->handler = new RunRepositoryCheckHandler(
            new class implements RepositorySuggestions {
                #[\Override]
                public function forProject(Uuid $projectId): array
                {
                    return [new RepositorySuggestion(RepositorySuggestionKey::BranchProtection, SuggestionMark::Blocking)];
                }
            },
            $checks,
            new RepositoryChecks($checks, $this->service(MessageBusInterface::class), $this->clock),
            $this->summaries,
            new RepositorySettingsReaders([$this->reader]),
            new RepositorySuggestionCatalog(),
            $this->em,
            $this->clock,
            new NullLogger(),
        );
    }

    public function test_a_certain_read_writes_the_items_the_settings_and_the_time(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking()];

        $this->check($repository, CheckTrigger::Hourly);

        $check = $this->checkOf($repository);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $check->checkedAt);
        self::assertNull($check->failure);
        self::assertSame(
            [['key' => 'branch-protection', 'mark' => 'blocking', 'state' => 'gap', 'gaps' => ['force_push_allowed', 'deletion_allowed'], 'parameters' => []]],
            $check->items,
        );
        self::assertSame('main', $check->settings['defaultBranch'] ?? null);
        self::assertSame(1, $check->acceptedReadTicket);
        self::assertSame([(string) $repository->id => 2], $this->summaries->current($repository->project)->blocking);
        self::assertCount(1, $this->events->events());
        $this->terminate();
        self::assertSame([(string) $repository->id], $this->signalled());
    }

    public function test_a_clear_read_stores_a_fitting_item(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::clear()];

        $this->check($repository, CheckTrigger::PageOpen);

        $item = $this->checkOf($repository)->items[0];
        self::assertSame('fits', $item['state']);
        self::assertSame([], $item['gaps']);
        self::assertSame([], $this->events->events());
    }

    public function test_a_failed_read_keeps_the_last_certain_items_and_sets_the_failure(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking(), new RepositorySettingsUnreadable('api_failed_500')];
        $this->check($repository, CheckTrigger::CheckAgain);
        $certain = $this->checkOf($repository);
        $items = $certain->items;
        $checkedAt = $certain->checkedAt;
        $this->clock->sleep(600);

        $this->check($repository, CheckTrigger::Hourly);

        $check = $this->checkOf($repository);
        self::assertSame($items, $check->items);
        self::assertEquals($checkedAt, $check->checkedAt);
        self::assertSame('api_failed_500', $check->failure);
        self::assertEquals(new \DateTimeImmutable(self::NOW)->modify('+600 seconds'), $check->attemptedAt);
        self::assertSame(2, $check->acceptedReadTicket);
        self::assertSame([(string) $repository->id => 2], $this->summaries->current($repository->project)->blocking, 'A failed read leaves the pause as it was.');
    }

    public function test_a_failed_first_read_stores_the_failure_and_no_items(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [new RepositorySettingsUnreadable('no_installation')];

        $this->check($repository, CheckTrigger::Hourly);

        $check = $this->checkOf($repository);
        self::assertSame('no_installation', $check->failure);
        self::assertSame([], $check->items);
        self::assertNull($check->checkedAt);
        self::assertTrue($this->summaries->current($repository->project)->isEmpty());
    }

    public function test_a_forge_with_no_reader_counts_as_unreadable(): void
    {
        $repository = $this->repository('unknown');

        $this->check($repository, CheckTrigger::Hourly);

        self::assertSame('unsupported_forge', $this->checkOf($repository)->failure);
        self::assertSame(0, $this->reader->reads);
    }

    public function test_a_fresh_hourly_read_writes_nothing_and_takes_no_ticket(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking()];
        $this->check($repository, CheckTrigger::Hourly);
        $this->terminate();
        $this->published = [];
        $before = $this->checkOf($repository);
        $ticket = $before->nextReadTicket;
        $this->clock->sleep(120);

        $this->check($repository, CheckTrigger::Hourly);

        self::assertSame(1, $this->reader->reads, 'Guard: the first check read the forge.');
        $after = $this->checkOf($repository);
        self::assertSame($ticket, $after->nextReadTicket);
        self::assertEquals($before->checkedAt, $after->checkedAt);
        $this->terminate();
        self::assertSame([], $this->published, 'An hourly reuse sends no signal.');
    }

    public function test_a_fresh_page_open_read_writes_nothing_but_signals_the_row(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking()];
        $this->check($repository, CheckTrigger::Hourly);
        $this->terminate();
        $this->published = [];
        $ticket = $this->checkOf($repository)->nextReadTicket;
        $events = \count($this->events->events());

        $this->check($repository, CheckTrigger::PageOpen);

        self::assertSame(1, $this->reader->reads);
        self::assertSame($ticket, $this->checkOf($repository)->nextReadTicket);
        self::assertCount($events, $this->events->events());
        $this->terminate();
        self::assertSame([(string) $repository->id], $this->signalled());
    }

    public function test_a_stale_read_reads_again_and_a_fresh_failed_attempt_counts_as_fresh(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [new RepositorySettingsUnreadable('api_failed_500'), FakeRepositorySettingsReader::clear()];
        $this->check($repository, CheckTrigger::Hourly);
        $this->clock->sleep(60);
        $this->check($repository, CheckTrigger::Hourly);
        self::assertSame(1, $this->reader->reads, 'A recent failed attempt is reused.');

        $this->clock->sleep(600);
        $this->check($repository, CheckTrigger::Hourly);

        self::assertSame(2, $this->reader->reads);
        self::assertNull($this->checkOf($repository)->failure);
    }

    public function test_check_again_reads_even_when_the_read_is_fresh(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking(), FakeRepositorySettingsReader::clear()];
        $this->check($repository, CheckTrigger::Hourly);

        $this->check($repository, CheckTrigger::CheckAgain);

        self::assertSame(2, $this->reader->reads);
        self::assertSame('fits', $this->checkOf($repository)->items[0]['state']);
        self::assertTrue($this->summaries->current($repository->project)->isEmpty());
    }

    public function test_an_older_read_that_ends_last_changes_nothing(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking()];
        $this->reader->duringRead = function () use ($repository): void {
            $this->reader->answers = [FakeRepositorySettingsReader::clear(), ...$this->reader->answers];
            $this->check($repository, CheckTrigger::CheckAgain);
        };

        $this->check($repository, CheckTrigger::Hourly);

        $check = $this->checkOf($repository);
        self::assertSame(2, $this->reader->reads);
        self::assertSame('fits', $check->items[0]['state'], 'The newer clear result stays.');
        self::assertSame(2, $check->acceptedReadTicket);
        self::assertSame(2, $check->nextReadTicket);
        self::assertTrue($this->summaries->current($repository->project)->isEmpty());
        self::assertSame([], $this->events->events(), 'Neither read changed the summary of an empty project.');
        $this->terminate();
        self::assertSame([(string) $repository->id], $this->signalled(), 'Only the accepted read signals.');
    }

    public function test_an_older_failure_that_ends_last_does_not_replace_a_newer_result(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [new RepositorySettingsUnreadable('api_failed_500')];
        $this->reader->duringRead = function () use ($repository): void {
            $this->reader->answers = [FakeRepositorySettingsReader::blocking(), ...$this->reader->answers];
            $this->check($repository, CheckTrigger::CheckAgain);
        };

        $this->check($repository, CheckTrigger::Hourly);

        $check = $this->checkOf($repository);
        self::assertNull($check->failure);
        self::assertNull($check->attemptedAt);
        self::assertSame('gap', $check->items[0]['state']);
        self::assertCount(1, $this->events->events());
        $this->terminate();
        self::assertCount(1, $this->published);
    }

    public function test_an_older_read_that_ends_first_is_replaced_by_the_newer_one(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking(), FakeRepositorySettingsReader::clear()];

        $this->check($repository, CheckTrigger::CheckAgain);
        $this->check($repository, CheckTrigger::CheckAgain);

        $check = $this->checkOf($repository);
        self::assertSame('fits', $check->items[0]['state']);
        self::assertSame(2, $check->acceptedReadTicket);
        self::assertCount(2, $this->events->events());
    }

    public function test_a_blocking_event_that_arrives_after_a_newer_clear_check_leaves_the_summary_empty(): void
    {
        $repository = $this->repository();
        $this->reader->answers = [FakeRepositorySettingsReader::blocking(), FakeRepositorySettingsReader::clear()];

        $this->check($repository, CheckTrigger::CheckAgain);
        $this->check($repository, CheckTrigger::CheckAgain);

        $events = $this->events->events();
        self::assertCount(2, $events, 'Guard: the blocking check and the clear check each fired an event.');
        self::assertEquals($repository->project->id, $events[0]->projectId);
        self::assertEquals($events[0]->projectId, $events[1]->projectId);
        // A listener that runs the event of the blocking check last reads the current summary, which the clear check emptied.
        self::assertTrue($this->summaries->current($repository->project)->isEmpty());
    }

    public function test_a_project_with_no_suggestions_gets_no_row(): void
    {
        $repository = $this->repository();
        $handler = new RunRepositoryCheckHandler(
            new class implements RepositorySuggestions {
                #[\Override]
                public function forProject(Uuid $projectId): array
                {
                    return [];
                }
            },
            $this->service(ForgeRepositoryCheckRepository::class),
            new RepositoryChecks($this->service(ForgeRepositoryCheckRepository::class), $this->service(MessageBusInterface::class), $this->clock),
            $this->summaries,
            new RepositorySettingsReaders([$this->reader]),
            new RepositorySuggestionCatalog(),
            $this->em,
            $this->clock,
            new NullLogger(),
        );

        $handler(new RunRepositoryCheckCommand($repository, CheckTrigger::CheckAgain));

        self::assertNull($this->service(ForgeRepositoryCheckRepository::class)->findOneOfRepository($repository));
        self::assertSame(0, $this->reader->reads);
    }

    private function check(ForgeRepository $repository, CheckTrigger $trigger): void
    {
        ($this->handler)(new RunRepositoryCheckCommand($repository, $trigger));
    }

    private function checkOf(ForgeRepository $repository): ForgeRepositoryCheck
    {
        $check = $this->service(ForgeRepositoryCheckRepository::class)->findOneOfRepository($repository) ?? throw new \LogicException('The check is gone.');
        $this->em->refresh($check);

        return $check;
    }

    private function repository(string $forge = FakeRepositorySettingsReader::FORGE): ForgeRepository
    {
        $owner = new User(fullName: 'Owner', email: 'check-'.++$this->sequence.'-'.uniqid().'@example.com', password: 'x');
        $project = new Project($owner, 'check-'.uniqid());
        $repository = new ForgeRepository($project, $forge, 'ext-'.$this->sequence, 'acme/repo-'.$this->sequence, ForgeRepositorySource::Installation, '77');
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }

    /** @return list<string> */
    private function signalled(): array
    {
        return array_map(static fn (Update $update): string => json_decode($update->getData(), true)['repositoryId'], $this->published);
    }

    private function terminate(): void
    {
        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
