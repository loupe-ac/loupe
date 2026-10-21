<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Small valid images, built by hand so a test needs no image library. Two sizes
 * of the PNG differ in every byte that matters to a delivery hash.
 */
final class TinyImage
{
    private const string JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    public static function png(int $width = 1, int $height = 1): string
    {
        $rows = str_repeat("\x00".str_repeat("\x00\x00\x00", $width), $height);

        return "\x89PNG\r\n\x1A\n"
            .self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($rows))
            .self::chunk('IEND', '');
    }

    public static function jpeg(): string
    {
        return (string) base64_decode(self::JPEG_BASE64, true);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
