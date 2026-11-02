<?php

declare(strict_types=1);

namespace App\Api\Command;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the committed public spec as it is. It reads a file, so it needs neither the
 * generator nor swagger-php at run time.
 */
final readonly class ShowOpenApiDocumentHandler
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/openapi/public.json')]
        private string $specFile,
    ) {
    }

    public function __invoke(ShowOpenApiDocumentCommand $command): string
    {
        $spec = file_get_contents($this->specFile);
        if (false === $spec) {
            throw new \RuntimeException(sprintf('The committed public spec "%s" is missing.', $this->specFile));
        }

        return $spec;
    }
}
