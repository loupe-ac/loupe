<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\KindPrompts;
use App\Module\Workflow\Contract\ParameterNames;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Reads the prompt of a work kind from config/workflows/prompts/<kind>.md and writes the instance URL into it. */
#[AsAlias(KindPrompts::class)]
final readonly class ShippedKindPrompts implements KindPrompts
{
    public const string INSTANCE_PLACEHOLDER = '{instance}';

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/workflows/prompts')]
        private string $directory,

        #[Autowire('%app.url%')]
        private string $instanceUrl,
    ) {
    }

    #[\Override]
    public function forKind(string $kind): ?string
    {
        // The kind comes from a template or a work failure policy, so check it before it becomes part of a path.
        if (1 !== preg_match(ParameterNames::PROMPT_PATTERN, $kind)) {
            return null;
        }
        $path = \sprintf('%s/%s.md', $this->directory, $kind);
        $text = is_file($path) ? file_get_contents($path) : false;

        return false === $text ? null : str_replace(self::INSTANCE_PLACEHOLDER, rtrim($this->instanceUrl, '/'), $text);
    }
}
