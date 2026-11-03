<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Module\Workflow\Messenger\EvaluateCard;
use App\Observability\TracesSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;

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
        $sampler = new TracesSampler($dsn, 1.0);

        self::assertSame(0.0, $sampler(self::context($parentSampled)));
    }

    public function test_a_dsn_samples_at_the_configured_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null)));
    }

    public function test_a_parent_decision_wins_over_the_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(1.0, $sampler(self::context(true)));
        self::assertSame(0.0, $sampler(self::context(false)));
    }

    /** @return iterable<string, array{string}> */
    public static function untracedRoutes(): iterable
    {
        yield 'health check' => ['ubermuda_health_check'];
        yield 'bridge heartbeat' => ['api_bridge_heartbeat'];
        yield 'legacy bridge heartbeat' => ['api_legacy_bridge_heartbeat'];
    }

    #[DataProvider('untracedRoutes')]
    public function test_an_untraced_route_samples_nothing(string $route): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 1.0);

        self::assertSame(0.0, $sampler(self::context(null, $route)));
        self::assertSame(0.0, $sampler(self::context(true, $route)));
    }

    public function test_another_route_samples_at_the_configured_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null, 'app_project_list')));
    }

    public function test_a_card_evaluation_samples_at_its_own_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.05, $sampler(self::context(null, name: EvaluateCard::class)));
    }

    public function test_another_message_samples_at_the_configured_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null, name: 'App\Module\Mail\Messenger\SendDigest')));
    }

    public function test_a_parent_decision_wins_over_a_card_evaluation_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(1.0, $sampler(self::context(true, name: EvaluateCard::class)));
        self::assertSame(0.0, $sampler(self::context(false, name: EvaluateCard::class)));
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
