<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Contract\CardMoveLock;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** A card is managed while the engine runs for its project, its project has a template, and nobody holds it. A card with work of the app on it takes no manual move, also while it is unmanaged. */
#[AsAlias(CardMoveGuard::class)]
final readonly class WorkflowCardMoveGuard implements CardMoveGuard
{
    private const string ANY_COLUMN = '*';

    public function __construct(
        private WorkflowAutomation $automation,
        private WorkLedger $ledger,
        private TemplateSource $templates,
        private FactsBuilder $facts,
        private ProjectRepository $projects,
        private CardTypeCatalog $cardTypes,

        /** @var iterable<CardMoveLock> */
        #[AutowireIterator('app.workflow_card_move_lock')]
        private iterable $locks,
    ) {
    }

    #[\Override]
    public function allows(CardSnapshot $card, ColumnRef $to, Actor $actor, ?CardEventCause $cause, bool $opensSession = false): bool
    {
        if (Actor::System === $actor || $to->id->equals($card->column->id)) {
            return true;
        }

        // Before the engine, template and hold checks, because the work of the app runs on the card in each of those states.
        foreach ($this->locks as $lock) {
            if ($lock->locks($card->id)) {
                return false;
            }
        }

        $project = $this->projects->find($card->projectId) ?? throw new \LogicException('A stored card has a project.');
        if (!$this->automation->runsFor($project)) {
            return true;
        }

        try {
            $template = $this->templates->forProject($card->projectId);
        } catch (TemplateMissing) {
            return true;
        }

        if ($this->ledger->isHeld($card->projectId, $card->id)) {
            return true;
        }

        $from = $this->facts->slotOfRef($project, $card->column);
        $target = $this->facts->slotOfRef($project, $to);

        $isParentRun = null;
        foreach ($template->manualMoves as $move) {
            if (!self::matches($move->from, $from) || !self::matches($move->to, $target)) {
                continue;
            }
            if (null === $move->by) {
                return true;
            }
            if (ManualMoveActor::Session === $move->by) {
                if ($opensSession && $this->sessionMayMove($card)) {
                    return true;
                }

                continue;
            }
            $isParentRun ??= $this->isParentRun($card, $cause);
            if ($isParentRun) {
                return true;
            }
        }

        return false;
    }

    /** A child needs the branch of its epic, and an epic needs its breakdown, so neither takes the session move. */
    private function sessionMayMove(CardSnapshot $card): bool
    {
        return null === $card->parentId && !$this->cardTypes->forProject($card->projectId)->get($card->type)->children;
    }

    /** An interactive run takes any name, so only an open stored worker run counts. */
    private function isParentRun(CardSnapshot $card, ?CardEventCause $cause): bool
    {
        $parentId = $card->parentId;
        if ('run' !== $cause?->type || null === $parentId) {
            return false;
        }

        return $this->ledger->isOpenWorkerOnCard((string) ($cause->fields['run'] ?? ''), $card->projectId, $parentId);
    }

    /** A column no slot links matches the wildcard alone. */
    private static function matches(string $end, ?string $slot): bool
    {
        return self::ANY_COLUMN === $end || (null !== $slot && $end === $slot);
    }
}
