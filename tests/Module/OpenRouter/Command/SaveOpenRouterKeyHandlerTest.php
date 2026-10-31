<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Command;

use App\Exception\DomainErrors;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyHandler;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\Event\OpenRouterKeyRemoved;
use App\Module\OpenRouter\Service\OpenRouterAnalytics;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use App\Tests\Module\OpenRouter\RecordedOpenRouterSignals;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class SaveOpenRouterKeyHandlerTest extends KernelTestCase
{
    use OpenRouterScenario;

    private const string SECRET = 'sk-or-v1-0123456789abcdef-a3f9';

    /** @var list<string> */
    private array $probedWith = [];

    private RecordedOpenRouterSignals $signals;

    private RecordingAuditor $audit;

    /** @var DispatchedEvents<OpenRouterKeyRemoved> */
    private DispatchedEvents $removed;

    public function test_a_working_key_is_stored_for_the_account_and_the_page_is_signalled(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'save-account');

        $this->handler(200)(new SaveOpenRouterKeyCommand($user, '  '.self::SECRET.' '));

        $key = $this->keyOf($user->id);
        self::assertSame(self::SECRET, $key->secret);
        self::assertSame('a3f9', $key->lastFour);
        self::assertSame(OpenRouterKeyState::Working, $key->state);
        self::assertSame([self::SECRET], $this->probedWith);
        $this->signals->assertSignalledUser($user->id);
        self::assertSame('account', $this->audit->record('openrouter.key_saved')->context['scope']);
    }

    public function test_a_working_key_is_stored_for_a_project_and_the_project_topic_is_signalled(): void
    {
        self::bootKernel();
        $project = $this->makeProject(self::getContainer(), $this->makeUser(self::getContainer(), 'save-project'));

        $this->handler(200)(new SaveOpenRouterKeyCommand($project, self::SECRET));

        $stored = $this->keys()->findOneBy(['project' => $project]);
        self::assertNotNull($stored);
        self::assertNull($stored->user);
        $this->signals->assertSignalledProject($project->id);
        self::assertSame('project', $this->audit->record('openrouter.key_saved')->context['scope']);
    }

    public function test_a_new_key_replaces_the_old_one_and_clears_a_refusal(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'save-replace');
        $old = $this->makeKey(self::getContainer(), $user, 'sk-or-v1-old-0001');
        $old->state = OpenRouterKeyState::Refused;
        $old->refusedSince = new \DateTimeImmutable('-1 day');
        $this->em()->flush();

        $this->handler(200)(new SaveOpenRouterKeyCommand($user, self::SECRET));

        $this->em()->clear();
        $keys = $this->keys()->findBy(['user' => $user]);
        self::assertCount(1, $keys);
        self::assertSame(self::SECRET, $keys[0]->secret);
        self::assertSame(OpenRouterKeyState::Working, $keys[0]->state);
        self::assertNull($keys[0]->refusedSince);
        self::assertNull($keys[0]->lastReadAt);
        self::assertCount(1, $this->removed->events());
        self::assertEquals($old->id, $this->removed->events()[0]->keyId);
        self::assertSame([0], $this->removed->transactionDepths());
    }

    public function test_a_first_key_dispatches_no_removed_event(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'save-first');

        $this->handler(200)(new SaveOpenRouterKeyCommand($user, self::SECRET));

        self::assertSame([], $this->removed->events());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_key_that_fails_the_test_is_not_stored_and_the_old_key_stays(int|\Throwable $answer, string $translationKey): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'save-refused');
        $this->makeKey(self::getContainer(), $user, 'sk-or-v1-old-0001');

        try {
            $this->handler($answer)(new SaveOpenRouterKeyCommand($user, self::SECRET));
            self::fail('The key must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['secret' => $translationKey], $e->errors);
        }

        $this->em()->clear();
        $keys = $this->keys()->findBy(['user' => $user]);
        self::assertCount(1, $keys);
        self::assertSame('sk-or-v1-old-0001', $keys[0]->secret);
        $this->signals->assertNothingSignalled();
        self::assertSame([], $this->removed->events());
        self::assertSame([], $this->audit->records('openrouter.key_saved'));
    }

    /** @return iterable<string, array{int|\Throwable, string}> */
    public static function refusals(): iterable
    {
        yield 'not a management key' => [403, 'openrouter.error.not_management_key'];
        yield 'refused' => [401, 'openrouter.error.key_refused'];
        yield 'server error' => [503, 'openrouter.error.unreachable'];
        yield 'timeout' => [new TransportException('timed out'), 'openrouter.error.unreachable'];
    }

    public function test_the_key_is_tested_before_anything_is_stored(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'save-order');

        try {
            $this->handler(403)(new SaveOpenRouterKeyCommand($user, self::SECRET));
        } catch (DomainErrors) {
        }

        // Guard: the probe ran, so the empty table below is about the order and not a skipped call.
        self::assertSame([self::SECRET], $this->probedWith);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT id FROM openrouter_keys WHERE user_id = :id', ['id' => (string) $user->id]) ?: null);
    }

    private function handler(int|\Throwable $answer): SaveOpenRouterKeyHandler
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->probedWith[] = $this->bearerOf($options['headers']);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new MockResponse('{}', ['http_code' => $answer]);
        }, 'https://openrouter.ai/api/v1/');
        $this->signals = new RecordedOpenRouterSignals();
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $this->removed = DispatchedEvents::of(self::getContainer(), OpenRouterKeyRemoved::class);

        return new SaveOpenRouterKeyHandler(
            new OpenRouterAnalytics($client, new MockClock('2026-10-30 12:00:00 UTC'), new NullLogger()),
            $this->keys(),
            $this->em(),
            $this->signals->publisher,
            $this->audit->auditor,
            $dispatcher,
        );
    }

    /** @param array<int|string, mixed> $headers */
    private function bearerOf(array $headers): string
    {
        foreach ($headers as $header) {
            if (\is_string($header) && str_starts_with($header, 'Authorization: Bearer ')) {
                return substr($header, \strlen('Authorization: Bearer '));
            }
        }

        throw new \LogicException('The probe sent no bearer key.');
    }

    private function keyOf(?\Symfony\Component\Uid\Uuid $userId): OpenRouterKey
    {
        $this->em()->clear();
        $key = $this->keys()->findOneBy(['user' => $userId]);
        self::assertNotNull($key);

        return $key;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
