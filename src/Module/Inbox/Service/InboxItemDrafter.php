<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Command\AskInboxDraft;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\AskInboxRunCommand;
use App\Module\Inbox\Command\AskInboxSuggestion;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\InboxLimits;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;

/**
 * Checks one item before it reaches the inbox: trims it, refuses what breaks a
 * rule, and resolves its links. Every caller that makes an item checks it here.
 */
final readonly class InboxItemDrafter
{
    public function __construct(
        private InboxLinkResolver $links,
        private SessionPromptLimit $promptLimit,
    ) {
    }

    /** @param string $prefix what each refused field name starts with, such as `items[0].` */
    public function draft(Project $project, AskInboxItem $input, string $prefix, ?Bridge $bridge): AskInboxDraft
    {
        $field = static fn (string $name): string => $prefix.$name;

        if (mb_strlen($input->title) > InboxItem::MAX_TITLE_LENGTH) {
            throw new DomainErrors([$field('title') => AskInboxHandler::TITLE_TOO_LONG]);
        }
        $title = trim($input->title);
        if ('' === $title) {
            throw new DomainErrors([$field('title') => AskInboxHandler::TITLE_BLANK]);
        }
        if (1 === preg_match('/[\r\n]/', $title)) {
            throw new DomainErrors([$field('title') => AskInboxHandler::TITLE_MULTILINE]);
        }

        if (null !== $input->body && mb_strlen($input->body) > InboxLimits::MAX_BODY_LENGTH) {
            throw new DomainErrors([$field('body') => AskInboxHandler::BODY_TOO_LONG]);
        }
        $body = null === $input->body || '' === trim($input->body) ? null : $input->body;

        if (\count($input->options) > InboxLimits::MAX_OPTIONS) {
            throw new DomainErrors([$field('options') => AskInboxHandler::TOO_MANY_OPTIONS]);
        }
        if (array_any($input->options, static fn (string $option): bool => mb_strlen($option) > InboxLimits::MAX_OPTION_LENGTH)) {
            throw new DomainErrors([$field('options') => AskInboxHandler::OPTION_TOO_LONG]);
        }
        $options = array_map(trim(...), $input->options);
        if (\in_array('', $options, true)) {
            throw new DomainErrors([$field('options') => AskInboxHandler::OPTION_BLANK]);
        }
        // An answer stores option indexes, so two equal options make it ambiguous.
        if (\count(array_unique($options)) !== \count($options)) {
            throw new DomainErrors([$field('options') => AskInboxHandler::OPTION_DUPLICATE]);
        }

        if (InboxItemKind::Todo === $input->kind && ([] !== $options || $input->multiple || $input->freeText)) {
            throw new DomainErrors([$field('options') => AskInboxHandler::TO_DO_WITH_ANSWER]);
        }
        if (InboxItemKind::Notice === $input->kind && ([] !== $options || $input->multiple || $input->freeText)) {
            throw new DomainErrors([$field('options') => AskInboxHandler::NOTICE_WITH_ANSWER]);
        }
        if (InboxItemKind::Question === $input->kind && [] === $options && !$input->freeText) {
            throw new DomainErrors([$field('options') => AskInboxHandler::QUESTION_WITHOUT_ANSWER]);
        }
        if ($input->multiple && \count($options) < 2) {
            throw new DomainErrors([$field('multiple') => AskInboxHandler::MULTIPLE_WITHOUT_OPTIONS]);
        }

        if (\count($input->cardIds) > InboxLimits::MAX_LINKS) {
            throw new DomainErrors([$field('cardIds') => AskInboxHandler::TOO_MANY_LINKS]);
        }
        if (\count($input->documentIds) > InboxLimits::MAX_LINKS) {
            throw new DomainErrors([$field('documentIds') => AskInboxHandler::TOO_MANY_LINKS]);
        }

        $reviewTarget = null;
        if (InboxItemKind::Review === $input->kind) {
            if ((null === $input->reviewDocumentId) === (null === $input->reviewPullRequestId)) {
                throw new DomainErrors([$field('reviewDocumentId') => AskInboxHandler::REVIEW_TARGET_REQUIRED]);
            }
            if ([] !== $options || $input->multiple || $input->freeText) {
                throw new DomainErrors([$field('options') => AskInboxHandler::REVIEW_WITH_ANSWER]);
            }
            $reviewTarget = null !== $input->reviewDocumentId
                ? ($this->links->documents($project, [$input->reviewDocumentId], $field('reviewDocumentId'))[0]
                    ?? throw new DomainErrors([$field('reviewDocumentId') => InboxLinkResolver::DOCUMENT_UNKNOWN]))
                : $this->links->pullRequest($project, $input->reviewPullRequestId ?? '', $field('reviewPullRequestId'));
        } elseif (null !== $input->reviewDocumentId || null !== $input->reviewPullRequestId) {
            throw new DomainErrors([$field('kind') => AskInboxHandler::REVIEW_TARGET_WRONG_KIND]);
        }

        $cardIds = $input->cardIds;
        $documentIds = $input->documentIds;
        if ($reviewTarget instanceof Document) {
            $documentIds[] = (string) $reviewTarget->id;
        } elseif ($reviewTarget instanceof CardPullRequest) {
            $cardIds[] = (string) $reviewTarget->card->id;
        }
        $cards = $this->links->cards($project, $cardIds, $field('cardIds'));
        $documents = $this->links->documents($project, $documentIds, $field('documentIds'));
        if (\count($cards) > InboxLimits::MAX_LINKS || \count($documents) > InboxLimits::MAX_LINKS) {
            throw new DomainErrors([$field('kind') => AskInboxHandler::TOO_MANY_LINKS]);
        }

        $linkedCardIds = array_map(static fn (Card $card): string => (string) $card->id, $cards);

        return new AskInboxDraft(
            kind: $input->kind,
            title: $title,
            body: $body,
            options: $options,
            multiple: $input->multiple,
            freeText: $input->freeText,
            blocking: $input->blocking ?? InboxItemKind::Question === $input->kind,
            cards: $cards,
            documents: $documents,
            reviewTarget: $reviewTarget,
            suggestedActions: $this->suggestions($input->suggestedActions, $input->kind, $linkedCardIds, $field('suggestedActions'), $bridge),
            dismissible: $input->dismissible,
        );
    }

    /**
     * Checks what the agent suggests. It never checks the fit, because the fit changes while the item is open.
     *
     * @param list<AskInboxSuggestion> $suggestions
     * @param list<string>             $linkedCardIds
     *
     * @return list<array{action: string, reason: string, recommended: bool, cardId: ?string, command: array{entry: string, branch: string, params: array<string, string>}|null, proposedEntry: ?string, prompt: ?string}>
     */
    private function suggestions(array $suggestions, InboxItemKind $kind, array $linkedCardIds, string $field, ?Bridge $bridge): array
    {
        if (\count($suggestions) > RunSuggestions::MAX_SUGGESTIONS) {
            throw new DomainErrors([$field => AskInboxHandler::TOO_MANY_SUGGESTIONS]);
        }

        $checked = [];
        foreach ($suggestions as $suggestion) {
            if (!(InboxActionKind::tryFrom($suggestion->action)?->isSuggestable() ?? false)) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_UNKNOWN_ACTION]);
            }
            if (isset($checked[$suggestion->action])) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_DUPLICATE]);
            }
            if ($suggestion->recommended && array_any($checked, static fn (array $earlier): bool => $earlier['recommended'])) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_TWO_RECOMMENDED]);
            }
            if (mb_strlen($suggestion->reason) > RunSuggestions::MAX_REASON_LENGTH) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_REASON_TOO_LONG]);
            }
            $reason = trim((string) preg_replace('/\s+/u', ' ', $suggestion->reason));
            if ('' === $reason) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_REASON_BLANK]);
            }
            if (null !== $suggestion->cardId && !\in_array(strtolower($suggestion->cardId), $linkedCardIds, true)) {
                throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_CARD_NOT_LINKED]);
            }
            $prompt = null;
            if (null !== $suggestion->prompt) {
                if (InboxActionKind::StartSession->value !== $suggestion->action) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_PROMPT_UNEXPECTED]);
                }
                if (mb_strlen($suggestion->prompt) > $this->promptLimit->maxLength()) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_PROMPT_TOO_LONG]);
                }
                $prompt = trim($suggestion->prompt);
                if ('' === $prompt) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_PROMPT_BLANK]);
                }
            }
            $command = null;
            if (InboxActionKind::RunCommand->value !== $suggestion->action) {
                if (null !== $suggestion->command || null !== $suggestion->proposedEntry) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_COMMAND_UNEXPECTED]);
                }
            } else {
                if (InboxItemKind::Todo !== $kind) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_RUN_COMMAND_KIND]);
                }
                if (null === $suggestion->command || '' === trim($suggestion->command->entry)) {
                    throw new DomainErrors([$field => AskInboxHandler::SUGGESTION_COMMAND_REQUIRED]);
                }
                $command = new AskInboxRunCommand(trim($suggestion->command->entry), $suggestion->command->branch, $suggestion->command->params);
                $error = SuggestedCommandCheck::error($command, $suggestion->proposedEntry, $bridge);
                if (null !== $error) {
                    throw new DomainErrors([$field => $error]);
                }
            }
            $checked[$suggestion->action] = [
                'action' => $suggestion->action,
                'reason' => $reason,
                'recommended' => $suggestion->recommended,
                'cardId' => null === $suggestion->cardId ? null : strtolower($suggestion->cardId),
                'command' => null === $command ? null : ['entry' => $command->entry, 'branch' => $command->branch, 'params' => $command->params],
                'proposedEntry' => null === $command ? null : $suggestion->proposedEntry,
                'prompt' => $prompt,
            ];
        }

        return array_values($checked);
    }
}
