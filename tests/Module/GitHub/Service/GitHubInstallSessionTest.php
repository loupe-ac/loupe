<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

final class GitHubInstallSessionTest extends TestCase
{
    private const string TOKEN = 'ghu_session_secret';

    private Session $session;

    private MockClock $clock;

    private RecordingLogger $logger;

    private GitHubInstallSession $installSession;

    private Encryptor $encryptor;

    private Project $project;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->clock = new MockClock('2026-11-10 12:00:00');
        $this->logger = new RecordingLogger();
        $this->encryptor = self::encryptor();
        $this->installSession = new GitHubInstallSession($stack, $this->encryptor, $this->clock, $this->logger);
        $this->project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'picker');
        new \ReflectionProperty(Project::class, 'id')->setValue($this->project, Uuid::v7());
    }

    public function test_the_token_is_stored_encrypted_and_read_back(): void
    {
        $this->signIn();

        self::assertStringNotContainsString(self::TOKEN, serialize($this->session->all()));
        self::assertSame(self::TOKEN, $this->installSession->token($this->projectId()));
    }

    public function test_the_token_is_valid_for_the_whole_of_thirty_minutes_less_a_second(): void
    {
        $this->signIn();
        $this->clock->modify('+1799 seconds');

        self::assertSame(self::TOKEN, $this->installSession->token($this->projectId()));
    }

    public function test_the_token_is_refused_and_removed_after_thirty_minutes(): void
    {
        $this->signIn();
        $this->clock->modify('+30 minutes');

        self::assertNull($this->installSession->token($this->projectId()));
        self::assertNull($this->installSession->pending(), 'The expired token leaves no trace in the session.');
        self::assertStringNotContainsString(self::TOKEN, serialize($this->session->all()));
        self::assertSame('github.picker_token_expired', $this->logger->records[0]['message']);
    }

    public function test_a_token_never_serves_another_project(): void
    {
        $this->signIn();

        self::assertNull($this->installSession->token('another-project'));
        self::assertNotNull($this->installSession->pending(), 'A look from another project does not remove the token.');
    }

    public function test_a_token_that_the_key_cannot_open_is_removed(): void
    {
        $this->signIn();
        $stack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $stack->push($request);
        $rotated = new GitHubInstallSession($stack, self::encryptor(), $this->clock, $this->logger);

        self::assertNull($rotated->token($this->projectId()));
        self::assertNull($rotated->pending());
        self::assertSame('github.picker_token_unreadable', $this->logger->records[0]['message']);
    }

    public function test_signing_in_drops_the_state_of_the_trip(): void
    {
        $pending = $this->installSession->beginSignIn($this->project, 'acme/api');
        $this->installSession->signIn($pending, self::TOKEN);

        $signedIn = $this->installSession->pending();
        self::assertNotNull($signedIn);
        self::assertTrue($signedIn->hasSignedIn());
        self::assertNull($signedIn->authorizeState);
        self::assertNull($signedIn->codeVerifier);
        self::assertNull($signedIn->installState);
        self::assertSame('acme/api', $signedIn->repository);
    }

    public function test_a_new_start_replaces_a_live_token(): void
    {
        $this->signIn();
        $this->installSession->beginInstall($this->project);

        self::assertNull($this->installSession->token($this->projectId()));
        self::assertNotNull($this->installSession->pending()?->installState);
    }

    public function test_clear_removes_the_token(): void
    {
        $this->signIn();
        $this->installSession->clear();

        self::assertNull($this->installSession->token($this->projectId()));
        self::assertNull($this->installSession->pending());
    }

    private function signIn(): void
    {
        $this->installSession->signIn($this->installSession->beginSignIn($this->project, null), self::TOKEN);
    }

    private function projectId(): string
    {
        return (string) $this->project->id;
    }

    private static function encryptor(): Encryptor
    {
        return new Encryptor(new EncryptionKeyProvider([base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))]));
    }
}
