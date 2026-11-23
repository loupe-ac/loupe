<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** One condition a worker asks to wait for. */
final readonly class WaitConditionRequest
{
    /**
     * @param non-empty-string     $key            a condition of the registry or a marker key of the template
     * @param array<string, mixed> $params         the parameters the worker gave
     * @param ?string              $pullRequestUrl the pull request the worker names
     */
    public function __construct(
        public string $key,
        public array $params = [],
        public ?string $pullRequestUrl = null,
    ) {
    }
}
