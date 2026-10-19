<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ParameterNames;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The prompt files of the workflow templates, one folder per template key under config/workflows. */
final readonly class TemplatePrompts
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/workflows')]
        private string $directory,
    ) {
    }

    public function has(string $templateKey, string $name): bool
    {
        $path = $this->path($templateKey, $name);

        return null !== $path && is_file($path);
    }

    /** @return ?string the prompt, or null when the template has no file for that name */
    public function read(string $templateKey, string $name): ?string
    {
        $path = $this->path($templateKey, $name);
        $text = null !== $path && is_file($path) ? file_get_contents($path) : false;

        return false !== $text ? $text : null;
    }

    // Check both names before they become part of a path.
    private function path(string $templateKey, string $name): ?string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $templateKey) || 1 !== preg_match(ParameterNames::PROMPT_PATTERN, $name)) {
            return null;
        }

        return \sprintf('%s/%s/prompts/%s.md', $this->directory, $templateKey, $name);
    }
}
