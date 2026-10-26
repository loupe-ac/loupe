<?php

declare(strict_types=1);

namespace App\Module\Workflow\Twig;

use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Board must not import Workflow, so the card page calls this function to find the rule its review switch links to. */
final class HumanReviewRuleExtension extends AbstractExtension
{
    private const string FORGE_WRITE = 'forge-write';

    private const string REQUIRE_HUMAN_REVIEW = 'require-human-review';

    public function __construct(
        private readonly TemplateSource $templates,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('workflow_human_review_rule', $this->ruleId(...))];
    }

    /**
     * The id of the first rule that approves a pull request, else of the first rule that requires a person to review it.
     * It is null when the project has no template copy or no such rule.
     */
    public function ruleId(Uuid|string $projectId): ?string
    {
        try {
            $rules = $this->templates->forProject($projectId instanceof Uuid ? $projectId : Uuid::fromString($projectId))->rules;
        } catch (TemplateMissing) {
            return null;
        }

        $approving = array_find($rules, static fn (Rule $rule): bool => array_any($rule->then, static fn (ActionCall $call): bool => self::FORGE_WRITE === $call->key && 'approve' === ($call->params['write'] ?? null)));
        $requiring = array_find($rules, static fn (Rule $rule): bool => array_any($rule->then, static fn (ActionCall $call): bool => self::REQUIRE_HUMAN_REVIEW === $call->key));

        return ($approving ?? $requiring)?->id;
    }
}
