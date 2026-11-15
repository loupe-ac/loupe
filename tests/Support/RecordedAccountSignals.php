<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mercure\AccountChangedPublisher;
use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\LiveUpdateTransactionBuffer;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/** An AccountChangedPublisher wired to a hub that records what the live publisher sends. */
final class RecordedAccountSignals
{
    private const string APP_URL = 'https://loupe.example';

    public AccountChangedPublisher $publisher;

    public readonly LiveUpdatePublisher $live;

    /** @var list<Update> */
    private array $updates = [];

    public function __construct()
    {
        $this->live = new LiveUpdatePublisher(
            new RequestStack(),
            new LiveUpdateTransactionBuffer(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): MockHub => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->updates[] = $update;

                    return 'id';
                },
            ),
        );
        $this->publisher = new AccountChangedPublisher(new UserTopicBuilder(self::APP_URL), $this->live);
    }

    /** Gives a user that no database holds an id, so a handler can read it. */
    public static function withId(User $user): User
    {
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v7());

        return $user;
    }

    public function assertNothingSentYet(): void
    {
        Assert::assertSame([], $this->updates);
    }

    /** Publishes what the handlers queued, and asserts that each update is one private signal of the area on the account topic. */
    public function assertSignalled(?Uuid $userId, string $area, int $times = 1): void
    {
        $this->live->publish();

        Assert::assertCount($times, $this->updates);
        foreach ($this->updates as $update) {
            Assert::assertSame([new UserTopicBuilder(self::APP_URL)->forAccount($userId ?? throw new \LogicException('The user has no id.'))], $update->getTopics());
            Assert::assertTrue($update->isPrivate());
            Assert::assertSame(\sprintf('{"type":"account.changed","area":"%s","origin":null}', $area), $update->getData());
        }
    }

    public function assertNothingSignalled(): void
    {
        $this->live->publish();

        Assert::assertSame([], $this->updates);
    }
}
