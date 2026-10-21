<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Service;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\NewScreenshot;
use App\Module\SiteReview\Service\ScreenshotDecoder;
use App\Tests\Support\TinyImage;
use PHPUnit\Framework\TestCase;

final class ScreenshotDecoderTest extends TestCase
{
    private ScreenshotDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ScreenshotDecoder();
    }

    public function test_a_png_is_accepted_with_its_size_and_hash(): void
    {
        $png = TinyImage::png(3, 2);

        $decoded = $this->decoder->decode(new NewScreenshot('image/png', base64_encode($png)));

        self::assertSame('image/png', $decoded->contentType);
        self::assertSame($png, $decoded->bytes);
        self::assertSame(\strlen($png), $decoded->size);
        self::assertSame(3, $decoded->width);
        self::assertSame(2, $decoded->height);
        self::assertSame(hash('sha256', $png), $decoded->sha256);
    }

    public function test_a_jpeg_is_accepted(): void
    {
        $decoded = $this->decoder->decode(new NewScreenshot('image/jpeg', base64_encode(TinyImage::jpeg())));

        self::assertSame('image/jpeg', $decoded->contentType);
        self::assertSame([1, 1], [$decoded->width, $decoded->height]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidImages(): iterable
    {
        yield 'not base64' => ['image/png', '***not base64***'];
        yield 'empty' => ['image/png', ''];
        yield 'text' => ['image/png', base64_encode('just some text')];
        yield 'svg' => ['image/png', base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>')];
        yield 'a png declared as a jpeg' => ['image/jpeg', base64_encode(TinyImage::png())];
        yield 'a jpeg declared as a png' => ['image/png', base64_encode(TinyImage::jpeg())];
        yield 'a type nobody accepts' => ['image/gif', base64_encode(TinyImage::png())];
        yield 'a png cut after its signature' => ['image/png', base64_encode("\x89PNG\r\n\x1A\n")];
        yield 'a jpeg that is only its marker' => ['image/jpeg', base64_encode("\xFF\xD8\xFF")];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidImages')]
    public function test_a_file_that_is_not_a_matching_image_is_refused(string $contentType, string $base64): void
    {
        try {
            $this->decoder->decode(new NewScreenshot($contentType, $base64));
            self::fail('The image must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['screenshot' => ScreenshotDecoder::INVALID], $error->errors);
        }
    }

    public function test_an_image_of_exactly_the_limit_is_accepted_and_one_byte_more_is_not(): void
    {
        $atLimit = TinyImage::png().str_repeat("\x00", ScreenshotDecoder::MAX_BYTES - \strlen(TinyImage::png()));
        self::assertSame(ScreenshotDecoder::MAX_BYTES, \strlen($atLimit));

        $decoded = $this->decoder->decode(new NewScreenshot('image/png', base64_encode($atLimit)));
        self::assertSame(ScreenshotDecoder::MAX_BYTES, $decoded->size);

        try {
            $this->decoder->decode(new NewScreenshot('image/png', base64_encode($atLimit."\x00")));
            self::fail('One byte over the limit must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['screenshot' => ScreenshotDecoder::TOO_LARGE], $error->errors);
        }
    }

    public function test_a_body_longer_than_any_allowed_image_is_refused_without_decoding(): void
    {
        $this->expectException(DomainErrors::class);

        $this->decoder->decode(new NewScreenshot('image/png', str_repeat('A', ScreenshotDecoder::MAX_BASE64_LENGTH + 1)));
    }
}
