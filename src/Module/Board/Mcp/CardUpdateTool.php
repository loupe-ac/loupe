<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\ChildDesignChoices;
use App\Module\Workflow\Contract\Actor;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Changes a card, one field at a time or several at once.
 *
 * There is no card_delete on purpose. An agent moves a card to a terminal
 * column; only a person removes one.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Change a card on the project board. Pass one of cardId or number to name the card. Every other field is optional, and a field you leave out keeps the value it has. status takes the slug of a column on this board. backlog is a status that always exists. The board does not draw Backlog as a column, and Backlog has its own page. Each board has its own columns, and board_columns lists them. A terminal column is where finished work goes. Moving a card to a terminal column stamps its completion time; moving it back to a column that is not terminal clears that stamp. A change of status appends the card to the end of the column it arrives in. pullRequestUrls, documentIds and artifactIds are the fields where leaving one out and sending an empty list differ: leave one out and those links stay, send an empty list and every link of that kind is removed. A documentId naming no document of this project is refused, and so is an artifactId naming no artifact of this project. relatedCards works the same way, and it replaces every link that touches the card, including links written from the other card. Send the whole set: the relatedCards of card_get goes back unchanged. Each entry takes a cardId and a kind (relates-to, blocks, blocked-by, has-follow-up or follow-up-of; a follow-up is a card with no parent that waits for the card it follows). type takes a type key from board_columns, and a type the project does not declare is refused. parentCardId puts the card under a card of this project: omit it to keep the parent, pass clearParent true to clear it (an empty string also clears it), or send the id of the new parent. Only a card of a type with the children capability can be a parent, such a card cannot have a parent, and it keeps its type while it has children. laneEnabled says whether the board draws a lane for a card of a type with the lane capability. Reporter cannot be changed, because it records who first raised the card. To finish a card, move it to a terminal column rather than asking for it to be deleted. childDesign says whether the tech design of the parent covers the card, for a card under a parent whose tech design is approved. Pass inherit to link that design: choose it for a fix inside a decision the design already made. Pass own for a card that asks an open question or changes a decision of the design: the card goes to Tech design, so pass no status with it. When the call sets parentCardId and the owner would get a question about the card, the call is refused until you pass the choice. The card number does not change, and you cannot set it. It is the short label that counts from 1 inside this project. requiresHumanReview says whether a person must review the pull request of the card: pass true to require it, and omit it to keep the setting. A bridge worker can only turn it on, and a call from a worker that passes false for a card with the switch on is refused. The card keeps its other fields when the call is refused. This tool takes the cardId or the number, never both. In the body, write another card as card:<number> and a pull request as pr:owner/repo#<number>; Loupe turns each into a link with a title tooltip. A title shows a token as plain text. values sets the typed values of the card, as an object that maps a key to its value, such as {"tech-design-interactive": true}. The workflow template of the project declares the keys and their types, and card_get lists the declared keys of a card in its values. Any caller may write a value, including a bridge worker. A key you leave out keeps its value. A key the template does not declare is refused, and so is a value of the wrong type. One bad entry refuses the whole call and changes nothing, and the error lists the declared keys.')]
final readonly class CardUpdateTool
{
    public const string NAME = 'card_update';
    public const string WORKER_LOWER_REFUSAL = 'requiresHumanReview: A bridge worker can only turn the switch on. Only a person turns it off.';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private UpdateCardHandler $updateCard,
        private ShowCardHandler $showCard,
        private CardPayload $payload,
        private BoardToolErrorMessages $errorMessages,
        private AgentRunCause $runCause,
        private ChildDesignDecision $childDesign,
        private ChildDesignChoices $choices,
        private AgentRunSource $runSource,
    ) {
    }

    /**
     * `string[]|null` not `list<string>|null`: the SDK infers a parameter's
     * JSON-schema `items` from the docblock type and parses only the `T[]` and
     * `array<T>` spellings, so `list<string>` publishes an array of anything.
     * The null default is what lets an omitted list mean "leave the links
     * alone" while an empty list clears them.
     *
     * @param string|null       $cardId              the id of the card to change, from card_list or card_create; pass it or number, never both
     * @param int|null          $number              the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param string|null       $title               a new title
     * @param string|null       $body                a new Markdown body, replacing the old one
     * @param string|null       $type                a new type key; board_columns lists the types this project declares
     * @param string|null       $status              the slug of a new column on this board; board_columns lists the slugs
     * @param string[]|null     $pullRequestUrls     the full set of pull request URLs the card carries; omit to keep the current links, send an empty list to remove them all
     * @param string[]|null     $documentIds         the full set of document ids the card carries; omit to keep the current links, send an empty list to remove them all
     * @param string[]|null     $artifactIds         the full set of artifact ids the card carries; omit to keep the current links, send an empty list to remove them all
     * @param array<mixed>|null $relatedCards        the full set of card links; omit to keep them, send an empty list to remove them all
     * @param string|null       $parentCardId        the id of a card of this project whose type has the children capability; omit to keep the parent, pass clearParent to clear it
     * @param bool|null         $clearParent         true removes the card from its parent; do not pass it with parentCardId
     * @param bool|null         $laneEnabled         whether the board draws a lane for this card, when its type has the lane capability; omit to keep the setting
     * @param string|null       $childDesign         inherit or own, for a card under a parent whose tech design is approved; inherit links that design, and own moves the card to Tech design
     * @param bool|null         $requiresHumanReview true when a person must review the pull request; a bridge worker can only turn it on; omit to keep the setting
     * @param array<mixed>|null $values              an object that maps a declared key to its value; card_get lists the declared keys in values; omit to keep every value
     *
     * @return CardSummary
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, ?string $title = null, ?string $body = null, ?string $type = null, ?string $status = null, ?array $pullRequestUrls = null, ?array $documentIds = null, ?array $artifactIds = null, #[Schema(items: BoardSubjectResolver::RELATED_CARD_ITEM)] ?array $relatedCards = null, ?string $parentCardId = null, ?bool $clearParent = null, ?bool $laneEnabled = null, #[Schema(enum: ChildDesignChoices::CHOICES)] ?string $childDesign = null, ?bool $requiresHumanReview = null, #[Schema(type: 'object', additionalProperties: true)] ?array $values = null): array
    {
        try {
            if (true === $clearParent) {
                if (null !== $parentCardId && '' !== $parentCardId) {
                    throw new ToolCallException('Pass clearParent or parentCardId, not both.');
                }
                $parentCardId = '';
            }

            $card = $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_WRITE);
            $column = $this->subjects->optionalColumn($card->project, $status);
            $workerSession = null !== $this->runSource->forProject($card->project);
            if (false === $requiresHumanReview && $card->requiresHumanReview && $workerSession) {
                throw new ToolCallException(self::WORKER_LOWER_REFUSAL);
            }

            $choice = $this->childDesign->resolve(
                $parentCardId,
                $card,
                $childDesign,
                null === $documentIds ? $this->linkedDocumentIds($card) : array_values($documentIds),
                null !== $status,
            );

            $update = fn (): Card => ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::Agent,
                title: $title,
                body: $body,
                type: $this->subjects->optionalType($card->project, $type),
                column: $column,
                pullRequestUrls: null === $pullRequestUrls ? null : array_values($pullRequestUrls),
                documentIds: null === $documentIds ? null : array_values($documentIds),
                artifactIds: null === $artifactIds ? null : array_values($artifactIds),
                relatedCards: $this->subjects->optionalRelatedCards($relatedCards),
                parentCardId: $parentCardId,
                laneEnabled: $laneEnabled,
                cause: null === $column ? null : $this->runCause->forCard($card),
                requiresHumanReview: $requiresHumanReview,
                onlyRaiseHumanReview: $workerSession,
                values: $values,
            ))->card;
            $card = null === $choice ? $update() : $this->choices->write($card->project, $update, $choice, $this->runCause->forCard($card), null === $parentCardId && null === $column ? $card : null);

            $view = ($this->showCard)(new ShowCardCommand($card));

            return $this->payload->forCard($view);
        } catch (EpicChildrenOpen $e) {
            throw new ToolCallException(\sprintf('status: This epic has open child cards %s. Move each of them to a terminal column first.', $e->cardList()), previous: $e);
        } catch (ChildDesignRefused $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (CardManaged $e) {
            throw new ToolCallException(CardManaged::AGENT_MESSAGE, previous: $e);
        } catch (DomainErrors $e) {
            if (\in_array(UpdateCardHandler::HUMAN_REVIEW_RAISE_ONLY, $e->errors, true)) {
                throw new ToolCallException(self::WORKER_LOWER_REFUSAL, previous: $e);
            }
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be changed. The error has been logged.', previous: $e);
        }
    }

    /** @return list<string> */
    private function linkedDocumentIds(Card $card): array
    {
        return array_values(array_map(static fn ($link): string => (string) $link->document->id, $card->documents->toArray()));
    }
}
