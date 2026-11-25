<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Workflow\Contract\Actor;

/**
 * Creates the card that carries a changed decision of a finished card. The new card lands in the backlog
 * and links to the finished card as its follow-up.
 */
final readonly class CreateDecisionFollowUpHandler
{
    public const string NOT_CHANGED = 'inbox.decision.error.follow_up_not_changed';

    public function __construct(
        private CreateCardHandler $createCard,
    ) {
    }

    public function __invoke(CreateDecisionFollowUpCommand $command): Card
    {
        $decision = $command->decision;
        if (null === $decision->changedAt) {
            throw new DomainErrors(['decision' => self::NOT_CHANGED]);
        }

        return ($this->createCard)(new CreateCardCommand(
            project: $decision->project,
            title: $command->title,
            body: $command->body,
            type: $command->type,
            reporter: Actor::Human,
            relatedCards: [new CardLinkInput((string) $decision->card->id, CardLinkKind::FollowUpOf)],
        ));
    }
}
