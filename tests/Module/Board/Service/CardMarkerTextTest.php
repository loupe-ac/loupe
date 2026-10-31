<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Service\CardMarkerText;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\PauseReasonText;
use App\Module\Workflow\Contract\StoredMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardMarkerTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pauseCodes(): iterable
    {
        yield 'a known code' => ['failed', 'marker.paused(The work failed)'];
        yield 'an unknown code' => ['review-failed', 'marker.paused.code(review-failed)'];
        yield 'an empty code' => ['', 'marker.paused.code()'];
    }

    #[DataProvider('pauseCodes')]
    public function test_a_pause_marker_reads_its_code_as_words(string $code, string $expected): void
    {
        self::assertSame($expected, $this->text()->label($this->marker('marker.paused', ['%reason%' => $code])));
    }

    public function test_any_other_marker_translates_its_label_with_its_params(): void
    {
        self::assertSame('marker.work_requested(-)', $this->text()->label($this->marker('marker.work_requested', [])));
    }

    public function test_a_usage_limit_marker_as_text_names_the_hard_limit_with_its_use_and_reads_the_reset_in_utc_whatever_the_server_zone(): void
    {
        $label = $this->inTimeZone('Europe/Paris', fn (): string => $this->text()->label($this->marker('marker.usage_limit', $this->usageParams('limit', 100))));

        self::assertSame('marker.usage_limit.limit(claude,marker.usage_limit.window.week(-),marker.usage_limit.cadence.week(-),100,95,Sun 1 Nov,17:00 UTC)', $label);
    }

    public function test_a_usage_limit_marker_as_html_marks_the_reset_for_the_browser_to_read_in_the_zone_of_the_reader(): void
    {
        $html = $this->inTimeZone('Europe/Paris', fn (): string => $this->text()->html($this->marker('marker.usage_limit', $this->usageParams('limit', 100))));

        self::assertStringContainsString('<time datetime="2026-11-01T17:00:00+00:00" data-controller="local-time" data-local-time-part-value="date">Sun 1 Nov</time>', $html);
        self::assertStringContainsString('<time datetime="2026-11-01T17:00:00+00:00" data-controller="local-time" data-local-time-part-value="time">17:00 UTC</time>', $html);
    }

    public function test_the_html_of_a_usage_limit_marker_escapes_the_account_name(): void
    {
        $html = $this->text()->html($this->marker('marker.usage_limit', [...$this->usageParams('limit', 100), '%account%' => '<b>x</b>']));

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function test_the_html_of_any_other_marker_is_its_escaped_text(): void
    {
        self::assertSame('marker.paused.code(&lt;x&gt;)', $this->text()->html($this->marker('marker.paused', ['%reason%' => '<x>'])));
    }

    public function test_a_usage_limit_marker_without_a_use_reads_the_unmeasured_wording(): void
    {
        $label = $this->text()->label($this->marker('marker.usage_limit', $this->usageParams('limit', '')));

        self::assertStringStartsWith('marker.usage_limit.limit_unmeasured(claude,', $label);
    }

    public function test_a_usage_limit_marker_at_the_stop_value_reads_the_stop_wording_with_the_stop_value(): void
    {
        $label = $this->text()->label($this->marker('marker.usage_limit', $this->usageParams('stop', 96)));

        self::assertStringStartsWith('marker.usage_limit.stop(claude,', $label);
        self::assertStringContainsString(',96,95,', $label);
    }

    public function test_a_window_of_another_length_reads_by_its_key(): void
    {
        $label = $this->text()->label($this->marker('marker.usage_limit', [...$this->usageParams('limit', 100), '%window%' => '3d']));

        self::assertStringStartsWith('marker.usage_limit.limit(claude,3d,3d,100,', $label);
    }

    /** @return array<string, string|int> */
    private function usageParams(string $cause, string|int $use): array
    {
        return ['%cause%' => $cause, '%account%' => 'claude', '%window%' => 'week', '%use%' => $use, '%stopAt%' => 95, '%resetsAt%' => new \DateTimeImmutable('2026-11-01 17:00:00 UTC')->getTimestamp()];
    }

    /** @param \Closure(): string $run */
    private function inTimeZone(string $zone, \Closure $run): string
    {
        $before = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            return $run();
        } finally {
            date_default_timezone_set($before);
        }
    }

    private function text(): CardMarkerText
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id.'('.([] === $params ? '-' : implode(',', $params)).')',
        );
        $reasons = $this->createStub(PauseReasonText::class);
        $reasons->method('forPause')->willReturnCallback(
            static fn (string $code): string => 'failed' === $code ? 'The work failed.' : $code,
        );

        return new CardMarkerText($translator, $reasons);
    }

    /** @param array<string, string|int> $params */
    private function marker(string $label, array $params): StoredMarker
    {
        return new StoredMarker('paused', MarkerKind::Stuck, $label, null, $params, new \DateTimeImmutable('2026-10-02 09:00:00'));
    }
}
