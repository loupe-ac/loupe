<?php

declare(strict_types=1);

namespace App\Tests\Api\Idempotency;

use App\Api\EventListener\ApplyApiBudgetOnRequest;
use App\Api\EventListener\ClaimIdempotencyKeyOnRequest;
use App\Api\EventListener\StoreIdempotentAnswerOnResponse;
use App\Api\Idempotency\Command\PurgeExpiredIdempotencyRecordsCommand;
use App\Api\Idempotency\Command\PurgeExpiredIdempotencyRecordsHandler;
use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use App\Api\Idempotency\Service\IdempotencyAccountPurger;
use App\Api\Idempotency\Service\IdempotencyRequestFingerprint;
use App\Api\Problem\ApiProblemException;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Security\AuthenticatedCredential;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** The retry rules of H10, driven against the real database. */
final class IdempotencyTest extends KernelTestCase
{
    private const string URL = 'https://loupe.test/api/v1/projects/x/cards';

    private MockClock $clock;

    private TokenStorage $tokens;

    private User $owner;

    private int $created = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-11-01 12:00:00');
        $this->tokens = new TokenStorage();
        $this->owner = $this->user('owner-'.bin2hex(random_bytes(4)).'@example.com');
        $this->signIn($this->owner, 'cred-a');
    }

    public function test_a_retry_on_a_finished_key_repeats_the_first_answer_and_creates_nothing(): void
    {
        $first = $this->send('key-1', '{"title":"A"}', 201, ['Location' => 'https://loupe.test/api/v1/projects/x/cards/7']);
        $retry = $this->send('key-1', '{"title":"A"}', 201);

        self::assertSame(1, $this->created);
        self::assertSame(201, $retry->getStatusCode());
        self::assertSame((string) $first->getContent(), (string) $retry->getContent());
        self::assertSame('https://loupe.test/api/v1/projects/x/cards/7', $retry->headers->get('Location'));
    }

    public function test_the_stored_answer_leaves_out_the_budget_headers(): void
    {
        $this->send('key-2', '{}', 201, ['RateLimit-Remaining' => '3', 'Retry-After' => '9']);

        $held = $this->repository()->findHeld('cred-a', 'key-2');

        self::assertNotNull($held);
        self::assertArrayNotHasKey('RateLimit-Remaining', $held['headers']);
        self::assertArrayNotHasKey('Retry-After', $held['headers']);
    }

    public function test_a_retry_while_the_first_request_runs_answers_409_at_any_clock_and_creates_nothing(): void
    {
        $this->claimOnly('key-3', '{"title":"A"}');

        foreach (['+0 seconds', '+3 minutes', '+23 hours'] as $shift) {
            $this->clock->modify($shift);
            $problem = $this->refusal('key-3', '{"title":"A"}');
            self::assertSame(409, $problem->problem->status);
            self::assertSame('idempotency_in_progress', $problem->problem->code);
        }

        self::assertSame(0, $this->created);
        self::assertSame(1, $this->rows('cred-a', 'key-3'));
    }

    public function test_a_first_request_that_died_after_its_commit_leaves_one_create(): void
    {
        $this->created = 1;
        $this->claimOnly('key-4', '{"title":"A"}');

        $problem = $this->refusal('key-4', '{"title":"A"}');

        self::assertSame('idempotency_in_progress', $problem->problem->code);
        self::assertSame(1, $this->created);
    }

    public function test_a_500_after_the_commit_is_stored_and_repeated_with_no_second_create(): void
    {
        $this->send('key-5', '{"title":"A"}', 500);
        $retry = $this->send('key-5', '{"title":"A"}', 201);

        self::assertSame(500, $retry->getStatusCode());
        self::assertSame(1, $this->created);
    }

    public function test_a_refusal_is_stored_and_repeated(): void
    {
        $this->send('key-6', '{}', 422);

        self::assertSame(422, $this->send('key-6', '{}', 201)->getStatusCode());
        self::assertSame(1, $this->created);
    }

    public function test_the_same_key_with_another_body_answers_409_key_reused(): void
    {
        $this->send('key-7', '{"title":"A"}', 201);

        $problem = $this->refusal('key-7', '{"title":"B"}');

        self::assertSame(409, $problem->problem->status);
        self::assertSame('idempotency_key_reused', $problem->problem->code);
    }

    public function test_the_same_key_from_another_credential_finds_nothing(): void
    {
        $this->send('key-8', '{}', 201);
        $this->signIn($this->owner, 'cred-b');

        $this->send('key-8', '{}', 201);

        self::assertSame(2, $this->created);
    }

    public function test_a_key_past_its_24_hours_is_claimed_again(): void
    {
        $this->send('key-9', '{}', 201);
        $this->clock->modify('+25 hours');

        $this->send('key-9', '{}', 201);

        self::assertSame(2, $this->created);
        self::assertSame(1, $this->rows('cred-a', 'key-9'));
    }

    public function test_a_running_claim_is_freed_only_when_its_key_ends(): void
    {
        $this->claimOnly('key-10', '{}');
        $this->clock->modify('+23 hours 59 minutes');
        self::assertSame(0, (new PurgeExpiredIdempotencyRecordsHandler($this->repository(), $this->clock))(new PurgeExpiredIdempotencyRecordsCommand()));
        self::assertSame('idempotency_in_progress', $this->refusal('key-10', '{}')->problem->code);

        $this->clock->modify('+2 minutes');

        self::assertSame(1, (new PurgeExpiredIdempotencyRecordsHandler($this->repository(), $this->clock))(new PurgeExpiredIdempotencyRecordsCommand()));
        self::assertSame(0, $this->rows('cred-a', 'key-10'));
    }

    public function test_a_blank_or_overlong_key_is_refused_with_a_422(): void
    {
        foreach (['   ', str_repeat('k', 256)] as $key) {
            $problem = $this->refusal($key, '{}');
            self::assertSame(422, $problem->problem->status);
            self::assertSame('Idempotency-Key', $problem->problem->errors[0]['field']);
        }
    }

    public function test_a_read_a_missing_key_a_missing_credential_and_a_private_path_claim_nothing(): void
    {
        $listener = $this->claimListener();
        $before = $this->totalRows();

        $get = Request::create(self::URL, Request::METHOD_GET, server: ['HTTP_IDEMPOTENCY_KEY' => 'k']);
        $none = Request::create(self::URL, Request::METHOD_POST);
        $private = Request::create('https://loupe.test/api/v1/bridge/bridges/x/heartbeat', Request::METHOD_POST, server: ['HTTP_IDEMPOTENCY_KEY' => 'k']);
        $old = Request::create('https://loupe.test/api/projects', Request::METHOD_POST, server: ['HTTP_IDEMPOTENCY_KEY' => 'k']);
        foreach ([$get, $none, $private, $old] as $request) {
            $listener($this->requestEvent($request));
        }
        $this->tokens->setToken(null);
        $listener($this->requestEvent(Request::create(self::URL, Request::METHOD_POST, server: ['HTTP_IDEMPOTENCY_KEY' => 'k'])));

        self::assertSame($before, $this->totalRows());
    }

    public function test_the_budget_listener_runs_before_the_claim_so_a_429_spends_no_key(): void
    {
        $order = [];
        foreach (self::getContainer()->get('event_dispatcher')->getListeners(KernelEvents::REQUEST) as $listener) {
            $object = \is_array($listener) ? $listener[0] : null;
            if ($object instanceof ApplyApiBudgetOnRequest || $object instanceof ClaimIdempotencyKeyOnRequest) {
                $order[] = $object::class;
            }
        }

        self::assertSame([ApplyApiBudgetOnRequest::class, ClaimIdempotencyKeyOnRequest::class], $order);
    }

    public function test_account_deletion_removes_the_rows_of_the_owner_only(): void
    {
        $this->send('key-11', '{}', 201);
        $other = $this->user('other-'.bin2hex(random_bytes(4)).'@example.com');
        $this->signIn($other, 'cred-o');
        $this->send('key-11', '{}', 201);

        new IdempotencyAccountPurger($this->repository())->purge($this->owner, new AccountDeletionCleanup());

        self::assertSame(0, $this->rows('cred-a', 'key-11'));
        self::assertSame(1, $this->rows('cred-o', 'key-11'));
    }

    /** Runs a whole request: claim, the handler once when the claim is new, then the stored answer. */
    /** @param array<string, string> $headers */
    private function send(string $key, string $body, int $status, array $headers = []): Response
    {
        $request = $this->request($key, $body);
        $event = $this->requestEvent($request);
        $this->claimListener()($event);
        if ($event->hasResponse()) {
            return $event->getResponse();
        }

        ++$this->created;
        $response = new Response('{"n":'.$this->created.'}', $status, $headers + ['Content-Type' => 'application/json']);
        new StoreIdempotentAnswerOnResponse($this->repository())(new ResponseEvent(self::getContainer()->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function claimOnly(string $key, string $body): void
    {
        $request = $this->request($key, $body);
        $this->claimListener()($this->requestEvent($request));
        self::assertTrue($request->attributes->has(ClaimIdempotencyKeyOnRequest::ATTRIBUTE));
    }

    private function refusal(string $key, string $body): ApiProblemException
    {
        try {
            $this->claimListener()($this->requestEvent($this->request($key, $body)));
        } catch (ApiProblemException $exception) {
            return $exception;
        }

        self::fail('The request was accepted.');
    }

    private function request(string $key, string $body): Request
    {
        return Request::create(self::URL, Request::METHOD_POST, server: ['HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private function claimListener(): ClaimIdempotencyKeyOnRequest
    {
        return new ClaimIdempotencyKeyOnRequest($this->repository(), new IdempotencyRequestFingerprint(), $this->tokens, $this->clock);
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(self::getContainer()->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function signIn(User $user, string $credentialId): void
    {
        $token = new UsernamePasswordToken($user, 'api', ['ROLE_USER']);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential($credentialId, ['ROLE_API_AGENT']));
        $this->tokens->setToken($token);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'x');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function repository(): IdempotencyRecordRepository
    {
        return self::getContainer()->get(IdempotencyRecordRepository::class);
    }

    private function rows(string $credential, string $key): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM api_idempotency_records WHERE credential = ? AND idempotency_key = ?', [$credential, $key]);
    }

    private function totalRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM api_idempotency_records');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
