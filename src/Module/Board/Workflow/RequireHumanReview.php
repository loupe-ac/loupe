<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\HumanReviewCause;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;

/**
 * Turns the human review switch of the card on. The switch then stores the artifacts that reach the card in its seen list,
 * and the card history names the rule and those artifacts. A card whose switch is on already changes nothing.
 */
final readonly class RequireHumanReview implements Action
{
    public const string KEY = 'require-human-review';
    public const string SWITCH_REFUSED = 'human-review-refused';

    public function __construct(
        private CardRepository $cards,
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.require_human_review', 'workflow.panel.action.require_human_review');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                cause: CardEventCause::workflowRule($context->ruleId),
                requiresHumanReview: true,
                humanReviewCause: HumanReviewCause::Rule,
                humanReviewRuleId: $context->ruleId,
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::SWITCH_REFUSED);
        }

        return ActionOutcome::done();
    }
}
