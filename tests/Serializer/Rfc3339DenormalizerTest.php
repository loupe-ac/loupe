<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use App\Serializer\Rfc3339Denormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class Rfc3339DenormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function acceptedDates(): iterable
    {
        yield 'whole second' => ['2026-11-01T17:58:08Z', '2026-11-01T17:58:08.000000+00:00'];
        yield 'milliseconds and Z' => ['2026-11-01T17:58:08.177Z', '2026-11-01T17:58:08.177000+00:00'];
        yield 'milliseconds and offset' => ['2026-11-01T12:37:46.027-05:00', '2026-11-01T12:37:46.027000-05:00'];
        yield 'nine digits keep six' => ['2026-11-01T12:37:46.288146826-05:00', '2026-11-01T12:37:46.288146-05:00'];
        yield 'leap day' => ['2028-02-29T00:00:00Z', '2028-02-29T00:00:00.000000+00:00'];
    }

    #[DataProvider('acceptedDates')]
    public function test_accepts_real_rfc3339_dates(string $text, string $expected): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $date = $this->serializer()->denormalize(['at' => $text], Payload::class)->at;
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertSame($expected, $date->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedDates(): iterable
    {
        yield 'words' => ['tomorrow'];
        yield 'empty' => [''];
        yield 'impossible day' => ['2026-02-30T17:58:08.177Z'];
        yield 'not a leap year' => ['2026-02-29T00:00:00Z'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
        yield 'leap second' => ['2026-02-28T23:59:60Z'];
        yield 'hour 24' => ['2026-02-28T24:00:00Z'];
        yield 'offset too large' => ['2026-02-28T12:00:00+24:00'];
        yield 'no zone' => ['2026-11-01T17:58:08.177'];
        yield 'trailing newline' => ["2026-11-01T17:58:08Z\n"];
        yield 'date only' => ['2026-11-01'];
    }

    #[DataProvider('refusedDates')]
    public function test_refuses_other_text(string $text): void
    {
        $this->expectException(NotNormalizableValueException::class);

        $this->serializer()->denormalize(['at' => $text], Payload::class);
    }

    public function test_keeps_the_deserialization_path_of_a_refusal(): void
    {
        try {
            $this->serializer()->denormalize(['at' => 'tomorrow'], Payload::class, null, ['collect_denormalization_errors' => true]);
            self::fail('A refusal was expected.');
        } catch (PartialDenormalizationException $e) {
            self::assertSame('at', $e->getNotNormalizableValueErrors()[0]->getPath());
        }
    }

    private function serializer(): Serializer
    {
        return new Serializer([new Rfc3339Denormalizer(), new DateTimeNormalizer(), new ObjectNormalizer(propertyTypeExtractor: new \Symfony\Component\PropertyInfo\PropertyInfoExtractor([], [new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor()]))]);
    }
}

final class Payload
{
    public function __construct(
        public \DateTimeImmutable $at,
    ) {
    }
}
