<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Workflow\Messenger\RunRuleAskAnswer;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Answers a question with options, free text or both, as the question allows.
 * The answer to a workflow question is final, and the same transaction queues
 * the message that runs the option.
 */
final readonly class AnswerInboxItemHandler
{
    public const string NOT_A_QUESTION = 'inbox.answer.error.not_a_question';
    public const string UNKNOWN_OPTION = 'inbox.answer.error.unknown_option';
    public const string ONE_OPTION = 'inbox.answer.error.one_option';
    public const string NO_FREE_TEXT = 'inbox.answer.error.no_free_text';
    public const string EMPTY = 'inbox.answer.error.empty';

    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(AnswerInboxItemCommand $command): InboxItem
    {
        $item = $command->item;
        if (!\in_array($item->kind, [InboxItemKind::Question, InboxItemKind::Workflow], true)) {
            throw new DomainErrors(['selectedOptions' => self::NOT_A_QUESTION]);
        }

        $text = trim($command->answerText);

        // Checked inside the lock, against the options as stored: an agent may
        // change the question after the page loaded it.
        $this->closer->respond($item, InboxItemState::Resolved, $command->throughAgent ? InboxCloser::OwnerThroughAgent : InboxCloser::Owner, null, 'selectedOptions', function (InboxItem $item) use ($command, $text): ?array {
            $selected = self::parseIndexes($command->selectedOptions, \count($item->options));
            if (null === $selected) {
                return ['selectedOptions' => self::UNKNOWN_OPTION];
            }
            if (!$item->multiple && \count($selected) > 1) {
                return ['selectedOptions' => self::ONE_OPTION];
            }
            if ('' !== $text && !$item->freeText) {
                return ['answerText' => self::NO_FREE_TEXT];
            }
            if ([] === $selected && '' === $text) {
                return [$item->freeText && [] === $item->options ? 'answerText' : 'selectedOptions' => self::EMPTY];
            }

            $item->selectedOptions = $selected;
            $item->note = '' === $text ? null : $text;
            if (InboxItemKind::Workflow === $item->kind) {
                $this->bus->dispatch(new RunRuleAskAnswer((string) $item->id, $selected[0]));
            }

            return null;
        });

        $this->auditor->record(
            'inbox.item_answered',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'projectId' => (string) $item->project->id,
                'optionCount' => \count($item->selectedOptions),
                'hasText' => null !== $item->note,
            ],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }

    /**
     * The distinct indexes in ascending order, or null when one is not an option.
     *
     * @return list<int>|null
     */
    private static function parseIndexes(string $raw, int $optionCount): ?array
    {
        $indexes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (1 !== preg_match('~^\d{1,4}$~', $part) || (int) $part >= $optionCount) {
                return null;
            }
            $indexes[(int) $part] = (int) $part;
        }
        ksort($indexes);

        return array_values($indexes);
    }
}
