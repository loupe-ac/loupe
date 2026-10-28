<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\RuleWorkLimit;
use App\Module\Workflow\Contract\RuleWorkLimits;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the request of a rule from the stored template copy of the project, and its pull request from the rule state of the card. */
#[AsAlias(RuleWorkLimits::class)]
final readonly class TemplateRuleWorkLimits implements RuleWorkLimits
{
    public function __construct(
        private ProjectTemplates $templates,
        private WorkflowRuleStateRepository $workflowRuleStates,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId, Uuid $cardId, string $ruleId): ?RuleWorkLimit
    {
        $rule = array_find($this->templates->of($projectId)->rules, static fn ($rule): bool => $rule->id === $ruleId);
        $params = $rule?->lead->params ?? [];
        if (null === $rule || !$rule->lead->traits->countsTowardLimit || !\is_int($params['limit'] ?? null) || !\is_string($params['kind'] ?? null)) {
            return null;
        }
        $reason = $params['reason'] ?? null;

        return new RuleWorkLimit(
            $params['kind'],
            \is_string($reason) ? $reason : null,
            $params['limit'],
            ($this->workflowRuleStates->findForCard($cardId)[$ruleId] ?? null)?->subjectPullRequestId,
        );
    }
}
