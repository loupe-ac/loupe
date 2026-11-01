<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Controller\Api\BridgeAccountReadingInput;
use App\Module\Bridge\Workflow\AccountUsageFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The latest reading of a usage window of a bridge account is above a percentage. A window that reset since the
 * reading, a reading with no use value and a bridge that has no name or another name all read false.
 */
final readonly class AccountUsage implements Condition, TimedCondition
{
    /** A bridge name of control-free characters, a slash, then an account name as BridgeAccountInput takes it. */
    public const string ACCOUNT_PATTERN = '/^\P{Cc}+\/[a-z][a-z0-9-]{0,39}$/Du';

    public const int MIN_ABOVE = 1;

    public const int MAX_ABOVE = 100;

    #[\Override]
    public static function key(): string
    {
        return 'bridge.account.usage';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('account', ParameterType::String, pattern: self::ACCOUNT_PATTERN, patternHint: '<bridge name>/<account>'),
            new Parameter('window', ParameterType::String, pattern: BridgeAccountReadingInput::WINDOW_PATTERN, patternHint: 'a window key such as session, week or 3d'),
            new Parameter('above', ParameterType::Int, min: self::MIN_ABOVE, max: self::MAX_ABOVE),
        ];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [AccountUsageFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $reading = $facts->get(AccountUsageFacts::class)->reading(ParameterValue::string($params, 'account'), ParameterValue::string($params, 'window'));

        return null !== $reading
            && null !== $reading->usedPercent
            && $reading->resetsAt > $facts->now
            && $reading->usedPercent > ParameterValue::int($params, 'above');
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage(
            $negated ? 'workflow.waiting.not.bridge_account_usage' : 'workflow.waiting.bridge_account_usage',
            ['%account%' => ParameterValue::string($params, 'account'), '%window%' => ParameterValue::string($params, 'window'), '%above%' => (string) ParameterValue::int($params, 'above')],
        );
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $at = $facts->get(AccountUsageFacts::class)->reading(ParameterValue::string($params, 'account'), ParameterValue::string($params, 'window'))?->resetsAt;

        return null !== $at && $at > $facts->now ? $at : null;
    }
}
