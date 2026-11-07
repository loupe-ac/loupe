<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\WorkflowSettings;
use App\Module\Workflow\Template\Template;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Names the epic branch from the two project settings. A project bound to no template gets the value of the Simple template. */
#[AsAlias(EpicBranches::class)]
final class TemplateEpicBranches implements EpicBranches, ResetInterface
{
    /** @var array<string, ?string> */
    private array $patterns = [];

    public function __construct(
        private readonly WorkflowSettings $settings,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId, int $number): ?string
    {
        $pattern = $this->patterns[$projectId->toRfc4122()] ??= $this->pattern($projectId);

        return null === $pattern ? null : str_replace(Template::EPIC_BRANCH_NUMBER, (string) $number, $pattern);
    }

    #[\Override]
    public function reset(): void
    {
        $this->patterns = [];
    }

    private function pattern(Uuid $projectId): ?string
    {
        $enabled = $this->settings->forProject($projectId, Template::SETTING_EPIC_BRANCHES)->value;
        $pattern = $this->settings->forProject($projectId, Template::SETTING_EPIC_BRANCH_PATTERN)->value;

        return true === $enabled && \is_string($pattern) ? $pattern : null;
    }
}
