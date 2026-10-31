<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\OpenRouter\Service\OpenRouterKeyChangedPublisher;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/** An OpenRouterKeyChangedPublisher wired to a hub that records what the live publisher sends. */
final class RecordedOpenRouterSignals
{
    private const string APP_URL = 'https://loupe.example';

    public OpenRouterKeyChangedPublisher $publisher;

    private readonly LiveUpdatePublisher $live;

    /** @var list<Update> */
    private array $updates = [];

    public function __construct()
    {
        $this->live = new LiveUpdatePublisher(
            new RequestStack(),
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
        $this->publisher = new OpenRouterKeyChangedPublisher(new UserTopicBuilder(self::APP_URL), new ProjectTopicBuilder(self::APP_URL), $this->live);
    }

    public function assertNothingSentYet(): void
    {
        Assert::assertSame([], $this->updates);
    }

    public function assertSignalledUser(?Uuid $userId, int $times = 1): void
    {
        $this->assertSignalledOn(new UserTopicBuilder(self::APP_URL)->forOpenRouter($userId ?? throw new \LogicException('The user has no id.')), $times);
    }

    public function assertSignalledProject(?Uuid $projectId, int $times = 1): void
    {
        $this->assertSignalledOn(new ProjectTopicBuilder(self::APP_URL)->forOpenRouter($projectId ?? throw new \LogicException('The project has no id.')), $times);
    }

    public function assertNothingSignalled(): void
    {
        $this->live->publish();

        Assert::assertSame([], $this->updates);
    }

    private function assertSignalledOn(string $topic, int $times): void
    {
        $this->live->publish();

        Assert::assertCount($times, $this->updates);
        foreach ($this->updates as $update) {
            Assert::assertSame([$topic], $update->getTopics());
            Assert::assertTrue($update->isPrivate());
            Assert::assertSame('{"type":"openrouter_key.changed","origin":null}', $update->getData());
        }
    }
}
