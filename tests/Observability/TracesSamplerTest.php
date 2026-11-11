<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Module\Workflow\Messenger\EvaluateCard;
use App\Observability\TracesSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Ubermuda\FeatureFlagsBundle\Dto\ResolvedFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Reader\InMemoryFeatureFlagReader;

final class TracesSamplerTest extends TestCase
{
    /** @return iterable<string, array{?string, ?bool}> */
    public static function noDsn(): iterable
    {
        yield 'empty DSN' => ['', null];
        yield 'unset DSN' => [null, null];
        yield 'empty DSN under a sampled parent' => ['', true];
        yield 'off string' => ['false', null];
        yield 'malformed DSN' => ['not-a-dsn', null];
        yield 'malformed DSN under a sampled parent' => ['not-a-dsn', true];
    }

    #[DataProvider('noDsn')]
    public function test_no_dsn_samples_nothing(?string $dsn, ?bool $parentSampled): void
    {
        $sampler = self::sampler($dsn, 1.0);

        self::assertSame(0.0, $sampler(self::context($parentSampled)));
    }

    public function test_a_dsn_samples_at_the_configured_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null)));
    }

    public function test_a_parent_decision_wins_over_the_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(1.0, $sampler(self::context(true)));
        self::assertSame(0.0, $sampler(self::context(false)));
    }

    /** @return iterable<string, array{string}> */
    public static function untracedRoutes(): iterable
    {
        yield 'health check' => ['ubermuda_health_check'];
        yield 'bridge heartbeat' => ['api_bridge_heartbeat'];
    }

    #[DataProvider('untracedRoutes')]
    public function test_an_untraced_route_samples_nothing(string $route): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 1.0);

        self::assertSame(0.0, $sampler(self::context(null, $route)));
        self::assertSame(0.0, $sampler(self::context(true, $route)));
    }

    public function test_another_route_samples_at_the_configured_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null, 'app_project_list')));
    }

    public function test_a_card_evaluation_samples_at_the_default_rate_with_no_flag_row(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.01, $sampler(self::context(null, name: EvaluateCard::class)));
    }

    /** @return iterable<string, array{int, float}> */
    public static function flagValues(): iterable
    {
        yield 'zero' => [0, 0.0];
        yield 'one per mille' => [1, 0.001];
        yield 'a quarter' => [250, 0.25];
        yield 'all' => [1000, 1.0];
        yield 'below zero' => [-5, 0.0];
        yield 'above all' => [5000, 1.0];
    }

    #[DataProvider('flagValues')]
    public function test_a_card_evaluation_samples_at_the_flag_rate(int $permille, float $rate): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25, $permille);

        self::assertSame($rate, $sampler(self::context(null, name: EvaluateCard::class)));
    }

    public function test_a_card_evaluation_falls_back_to_the_default_when_the_flag_read_fails(): void
    {
        $reader = self::createStub(FeatureFlagReaderInterface::class);
        $reader->method('get')->willThrowException(new \RuntimeException('relation "feature_flag" does not exist'));
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25, new FeatureFlagService($reader, new NullLogger()));

        self::assertSame(0.01, $sampler(self::context(null, name: EvaluateCard::class)));
    }

    public function test_the_flag_leaves_other_transactions_at_the_configured_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25, 1000);

        self::assertSame(0.25, $sampler(self::context(null)));
        self::assertSame(0.25, $sampler(self::context(null, 'app_project_list')));
        self::assertSame(0.25, $sampler(self::context(null, name: 'App\Module\Mail\Messenger\SendDigest')));
    }

    public function test_other_transactions_never_read_the_flag(): void
    {
        $reader = self::createMock(FeatureFlagReaderInterface::class);
        $reader->expects(self::never())->method('get');
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25, new FeatureFlagService($reader, new NullLogger()));

        self::assertSame(0.25, $sampler(self::context(null, 'app_project_list')));
    }

    public function test_another_message_samples_at_the_configured_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null, name: 'App\Module\Mail\Messenger\SendDigest')));
    }

    public function test_a_parent_decision_wins_over_a_card_evaluation_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25);

        self::assertSame(1.0, $sampler(self::context(true, name: EvaluateCard::class)));
        self::assertSame(0.0, $sampler(self::context(false, name: EvaluateCard::class)));
    }

    public function test_a_parent_decision_wins_over_the_flag_rate(): void
    {
        $sampler = self::sampler('https://key@sentry.example/1', 0.25, 0);

        self::assertSame(1.0, $sampler(self::context(true, name: EvaluateCard::class)));
    }

    private static function sampler(?string $dsn, float $sampleRate, ?int $evaluateCardPermille = null): TracesSampler
    {
        $flags = null === $evaluateCardPermille
            ? []
            : [new ResolvedFlag(TracesSampler::EVALUATE_CARD_RATE_FLAG, FeatureFlagType::Int, $evaluateCardPermille)];

        return new TracesSampler($dsn, $sampleRate, new FeatureFlagService(new InMemoryFeatureFlagReader($flags), new NullLogger()));
    }

    private static function context(?bool $parentSampled, ?string $route = null, string $name = TransactionContext::DEFAULT_NAME): SamplingContext
    {
        $transaction = TransactionContext::make()->setName($name);
        if (null !== $route) {
            $transaction->setData(['route' => $route]);
        }

        return SamplingContext::getDefault($transaction)->setParentSampled($parentSampled);
    }
}
