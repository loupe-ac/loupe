<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/**
 * Writes a value of the card as the system, so a rule can set an internal value. The write goes
 * through the card handler, which dispatches the change and updates the card page.
 */
final readonly class SetCardValue implements Action
{
    public const string VALUE_REFUSED = 'value-refused';

    public const string KEY = 'set-value';

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
        return [
            new Parameter('key', ParameterType::ValueKey),
            new Parameter('value', ParameterType::Bool),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true, option: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        $key = (string) ($params['key'] ?? '');

        return new ActionDescription(
            'workflow.settings.action.set_value',
            'workflow.panel.action.set_value',
            panelParams: ['%key%' => $key, '%value%' => true === ($params['value'] ?? null) ? 'on' : 'off'],
            settingsDetail: $key,
        );
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
                values: [$context->string('key') => $context->bool('value')],
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::VALUE_REFUSED);
        }

        return ActionOutcome::done();
    }
}
