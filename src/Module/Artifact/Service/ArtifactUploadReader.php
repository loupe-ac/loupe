<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Api\Problem\ApiProblemException;
use App\Module\Artifact\Command\ArtifactFileInput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/** Pairs the uploaded files of a multipart request with their paths, and checks the card id. */
final readonly class ArtifactUploadReader
{
    public function __construct(
        private ArtifactApiProblems $problems,
    ) {
    }

    /**
     * @param list<string> $paths
     * @param array<mixed> $files
     *
     * @return list<ArtifactFileInput>
     *
     * @throws ApiProblemException `validation_failed` when the paths and the files do not pair one to one, or the card is no uuid
     */
    public function read(array $paths, array $files, ?string $card): array
    {
        $errors = [];
        if (!array_is_list($files) || \count($files) !== \count($paths) || array_any($files, static fn (mixed $file): bool => !$file instanceof UploadedFile)) {
            $errors = $this->problems->pathsMismatch();
        }
        $cardId = trim($card ?? '');
        if ('' !== $cardId && !Uuid::isValid($cardId)) {
            $errors = [...$errors, ...$this->problems->cardNotFound()];
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        $inputs = [];
        foreach ($files as $index => $file) {
            \assert($file instanceof UploadedFile);
            $contents = file_get_contents($file->getPathname());
            if (false === $contents) {
                throw new \RuntimeException('An uploaded file could not be read.');
            }

            $inputs[] = new ArtifactFileInput($paths[$index], $contents, $file->getClientMimeType());
        }

        return $inputs;
    }
}
