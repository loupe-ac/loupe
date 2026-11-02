<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/** The hash that tells a retry from a second, different request under the same key. */
final readonly class IdempotencyRequestFingerprint
{
    public function of(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            $this->bodyOf($request),
        ]));
    }

    /** PHP parses a multipart body away, so its fields and the digest of each file stand for it. */
    private function bodyOf(Request $request): string
    {
        $body = $request->getContent();
        if ('' !== $body || ($request->request->count() + $request->files->count()) < 1) {
            return $body;
        }

        return json_encode([$request->request->all(), self::digestOf($request->files->all())], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $files
     *
     * @return array<mixed>
     */
    private static function digestOf(array $files): array
    {
        return array_map(
            static fn (mixed $file): mixed => match (true) {
                $file instanceof UploadedFile => [$file->getClientOriginalName(), $file->getClientMimeType(), hash_file('sha256', $file->getPathname())],
                \is_array($file) => self::digestOf($file),
                default => null,
            },
            $files,
        );
    }
}
