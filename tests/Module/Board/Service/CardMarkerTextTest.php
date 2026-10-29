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
