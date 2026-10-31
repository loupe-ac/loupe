<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Command;

use App\Module\Bridge\Command\RecordBilledCostHandler;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\OpenRouter\Command\ReadBilledCostCommand;
use App\Module\OpenRouter\Command\ReadBilledCostHandler;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\Event\OpenRouterKeyRefused;
use App\Module\OpenRouter\Event\OpenRouterKeyWorks;
use App\Module\OpenRouter\Messenger\ReadBilledCost;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\OpenRouterAnalytics;
use App\Module\OpenRouter\Service\OpenRouterKeyChangedPublisher;
use App\Module\OpenRouter\Service\OpenRouterKeyResolver;
use App\Module\Project\Entity\Project;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReadBilledCostHandlerTest extends KernelTestCase
{
    use OpenRouterScenario;

    private const string SECRET = 'sk-or-v1-0123456789abcdef-a3f9';

    /** @var list<array{headers: array<int|string, mixed>, body: string}> */
    private array $requests = [];

    public function test_the_read_sends_the_run_key_and_not_the_run_id_as_the_session(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-session');

        $this->read($row, $this->total('0.036'));

        self::assertCount(1, $this->requests);
        $body = json_decode($this->requests[0]['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame((string) $row->runKey, $body['filters'][0]['value']);
        self::assertNotSame((string) $row->runId, $body['filters'][0]['value']);
        self::assertContains('Authorization: Bearer '.self::SECRET, $this->requests[0]['headers']);
        self::assertSame('2026-10-30T09:55:00+00:00', $body['time_range']['start']);
        self::assertNotNull($project->id);
    }

    public function test_the_first_total_waits_for_a_second_read_that_agrees(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-agree');

        $this->read($row, $this->total('0.036'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Pending, $stored->state);
        self::assertSame('0.036000', $stored->firstReadCost);
        self::assertSame(1, $stored->attempts);
        $reads = $this->scheduledReads();
        self::assertCount(1, $reads);
        $message = $reads[0]->getMessage();
        self::assertInstanceOf(ReadBilledCost::class, $message);
        self::assertSame((string) $row->runId, $message->runId);
        self::assertSame(180_000, $reads[0]->last(DelayStamp::class)?->getDelay());

        $this->read($row, $this->total('0.036'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Billed, $stored->state);
        self::assertSame('0.036000', $stored->costUsd);
        self::assertSame(2, $stored->attempts);
        self::assertCount(1, $this->scheduledReads(), 'A billed cost schedules no read.');
    }

    public function test_a_different_total_replaces_the_first_one_and_waits_another_step(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-differ');

        $this->read($row, $this->total('0.020'));
        $this->read($row, $this->total('0.036'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Pending, $stored->state);
        self::assertSame('0.036000', $stored->firstReadCost);
        self::assertSame(2, $stored->attempts);
        $reads = $this->scheduledReads();
        self::assertSame(300_000, $reads[1]->last(DelayStamp::class)?->getDelay());
    }

    public function test_a_read_with_no_answer_waits_for_the_next_step_and_keeps_the_first_total(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-silent');

        $this->read($row, $this->total('0.020'));
        $this->read($row, new MockResponse('{}', ['http_code' => 503]));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Pending, $stored->state);
        self::assertSame('0.020000', $stored->firstReadCost);
        self::assertSame(2, $stored->attempts);
        self::assertCount(2, $this->scheduledReads());
    }

    public function test_the_last_read_with_no_answer_gives_up(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-last-silent', attempts: 4);

        $this->read($row, new TransportException('timed out'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::NoAnswer, $stored->reason);
        self::assertSame([], $this->scheduledReads());
    }

    public function test_the_last_read_with_an_empty_answer_finds_no_cost(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-last-empty', attempts: 4);

        $this->read($row, new MockResponse('{"data":[]}'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::NoCostFound, $stored->reason);
    }

    public function test_the_last_read_that_still_differs_gives_up_with_no_answer(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-last-differs', attempts: 4, firstReadCost: '0.020000');

        $this->read($row, $this->total('0.036'));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::NoAnswer, $stored->reason);
    }

    public function test_an_empty_answer_before_the_last_read_waits(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-empty');

        $this->read($row, new MockResponse('{"data":[]}'));

        self::assertSame(BilledCostState::Pending, $this->stored($row)->state);
        self::assertCount(1, $this->scheduledReads());
    }

    public function test_a_refused_key_ends_the_read_and_is_marked_refused(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-refused');
        $refused = DispatchedEvents::of(self::getContainer(), OpenRouterKeyRefused::class);

        $this->read($row, new MockResponse('{}', ['http_code' => 401]));

        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::KeyRefused, $stored->reason);
        self::assertSame([], $this->scheduledReads());
        $key = $this->keys()->findOneBy(['project' => $project]) ?? throw new \LogicException('The key exists.');
        self::assertSame(OpenRouterKeyState::Refused, $key->state);
        self::assertEquals(new \DateTimeImmutable('2026-10-30 10:00:00'), $key->refusedSince);
        self::assertCount(1, $refused->events());
        self::assertEquals($key->id, $refused->events()[0]->keyId);
        self::assertTrue($refused->events()[0]->forProject);
    }

    public function test_a_read_that_works_marks_a_refused_key_working_again(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-works');
        $key = $this->keys()->findOneBy(['project' => $project]) ?? throw new \LogicException('The key exists.');
        $key->state = OpenRouterKeyState::Refused;
        $key->refusedSince = new \DateTimeImmutable('2026-10-29 10:00:00');
        $this->em()->flush();
        $works = DispatchedEvents::of(self::getContainer(), OpenRouterKeyWorks::class);

        $this->read($row, new MockResponse('{"data":[]}'));

        $this->em()->clear();
        $key = $this->keys()->findOneBy(['project' => $project]) ?? throw new \LogicException('The key exists.');
        self::assertSame(OpenRouterKeyState::Working, $key->state);
        self::assertNull($key->refusedSince);
        self::assertEquals(new \DateTimeImmutable('2026-10-30 10:00:00'), $key->lastReadAt);
        self::assertCount(1, $works->events());
    }

    public function test_a_working_key_announces_nothing_on_a_read_that_works(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-works-quiet');
        $works = DispatchedEvents::of(self::getContainer(), OpenRouterKeyWorks::class);

        $this->read($row, $this->total('0.036'));

        $key = $this->keys()->findOneBy(['project' => $project]) ?? throw new \LogicException('The key exists.');
        self::assertEquals(new \DateTimeImmutable('2026-10-30 10:00:00'), $key->lastReadAt);
        self::assertSame([], $works->events());
    }

    public function test_a_read_with_no_answer_leaves_the_key_alone(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-key-alone');

        $this->read($row, new MockResponse('{}', ['http_code' => 500]));

        $key = $this->keys()->findOneBy(['project' => $project]) ?? throw new \LogicException('The key exists.');
        self::assertSame(OpenRouterKeyState::Working, $key->state);
        self::assertNull($key->lastReadAt);
    }

    public function test_a_key_removed_since_the_run_ended_records_no_key_without_a_call(): void
    {
        self::bootKernel();
        [$project, $row] = $this->scenario('read-removed');
        $this->keys()->deleteForProject($project->requireId());
        $this->em()->clear();

        $this->read($row, $this->total('0.036'));

        self::assertSame([], $this->requests);
        $stored = $this->stored($row);
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::NoKey, $stored->reason);
    }

    public function test_a_final_row_is_not_read_again(): void
    {
        self::bootKernel();
        [, $row] = $this->scenario('read-final', state: BilledCostState::Billed);

        $this->read($row, $this->total('0.036'));

        self::assertSame([], $this->requests);
    }

    public function test_a_row_that_does_not_exist_is_ignored(): void
    {
        self::bootKernel();

        $this->handler($this->total('0.036'))(new ReadBilledCostCommand(Uuid::v7()));

        self::assertSame([], $this->requests);
    }

    /** @return array{Project, WorkerRunBilledCost} */
    private function scenario(string $name, int $attempts = 0, ?string $firstReadCost = null, BilledCostState $state = BilledCostState::Pending): array
    {
        $container = self::getContainer();
        $project = $this->makeProject($container, $this->makeUser($container, $name));
        $this->makeKey($container, $project, self::SECRET);
        $row = new WorkerRunBilledCost(Uuid::v7(), Uuid::v4(), $project, 'card', Uuid::v7(), new \DateTimeImmutable('2026-10-30 10:00:00'));
        $row->attempts = $attempts;
        $row->firstReadCost = $firstReadCost;
        $row->state = $state;
        $this->em()->persist($row);
        $this->em()->flush();

        return [$project, $row];
    }

    private function total(string $usd): MockResponse
    {
        return new MockResponse('{"data":[{"total_usage":'.$usd.',"request_count":2}]}');
    }

    private function read(WorkerRunBilledCost $row, MockResponse|TransportException $response): void
    {
        $this->handler($response)(new ReadBilledCostCommand($row->runId));
    }

    private function handler(MockResponse|TransportException $response): ReadBilledCostHandler
    {
        $container = self::getContainer();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['headers' => $options['headers'], 'body' => (string) $options['body']];
            if ($response instanceof TransportException) {
                throw $response;
            }

            return $response;
        }, 'https://openrouter.ai/api/v1/');
        $clock = new MockClock('2026-10-30 10:00:00 UTC');

        return new ReadBilledCostHandler(
            $this->service(WorkerRunBilledCostRepository::class),
            $this->service(OpenRouterKeyResolver::class),
            $this->service(OpenRouterKeyRepository::class),
            new OpenRouterAnalytics($client, $clock, new NullLogger()),
            $this->service(RecordBilledCostHandler::class),
            $this->service(OpenRouterKeyChangedPublisher::class),
            $this->service(EntityManagerInterface::class),
            $this->service(MessageBusInterface::class),
            $this->service(EventDispatcherInterface::class),
            $clock,
        );
    }

    private function stored(WorkerRunBilledCost $row): WorkerRunBilledCost
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRunBilledCost::class, $row->runId) ?? throw new \LogicException('The row exists.');
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function scheduledReads(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ReadBilledCost));
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
