<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\NewScreenshot;

/**
 * Checks the image of a note before anything is stored. The server draws
 * nothing, so it needs no image library: it reads the header of a finished
 * JPEG or PNG and refuses every other type, SVG included.
 */
final readonly class ScreenshotDecoder
{
    public const string INVALID = 'screenshot_invalid';
    public const string TOO_LARGE = 'screenshot_too_large';

    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** The base64 length of MAX_BYTES, padding included. */
    public const int MAX_BASE64_LENGTH = 2796204;

    private const string JPEG_MAGIC = "\xFF\xD8\xFF";
    private const string PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    /**
     * @throws DomainErrors with the field `screenshot`
     */
    public function decode(NewScreenshot $screenshot): DecodedScreenshot
    {
        if (\strlen($screenshot->base64) > self::MAX_BASE64_LENGTH) {
            throw new DomainErrors(['screenshot' => self::TOO_LARGE]);
        }

        $bytes = base64_decode($screenshot->base64, true);
        if (false === $bytes || '' === $bytes) {
            throw new DomainErrors(['screenshot' => self::INVALID]);
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new DomainErrors(['screenshot' => self::TOO_LARGE]);
        }

        $contentType = match (true) {
            str_starts_with($bytes, self::JPEG_MAGIC) => 'image/jpeg',
            str_starts_with($bytes, self::PNG_MAGIC) => 'image/png',
            default => null,
        };
        if (null === $contentType || $contentType !== $screenshot->contentType) {
            throw new DomainErrors(['screenshot' => self::INVALID]);
        }

        // A truncated image makes the reader raise a notice, and the framework
        // turns a notice into an exception. The result is what counts here.
        set_error_handler(static fn (): bool => true);
        try {
            $size = getimagesizefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        $expected = 'image/jpeg' === $contentType ? \IMAGETYPE_JPEG : \IMAGETYPE_PNG;
        if (false === $size || $size[2] !== $expected || $size[0] < 1 || $size[1] < 1) {
            throw new DomainErrors(['screenshot' => self::INVALID]);
        }

        return new DecodedScreenshot($contentType, $bytes, \strlen($bytes), $size[0], $size[1], hash('sha256', $bytes));
    }
}
