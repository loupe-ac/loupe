<?php

declare(strict_types=1);

namespace App\Module\Inbox\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Command\ShowCardDecisionsCommand;
use App\Module\Inbox\Command\ShowCardDecisionsHandler;
use App\Module\Inbox\Command\ShowCardDecisionsView;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Form\ChangeInboxDecisionFormType;
use App\Module\Inbox\Form\ChangeInboxDecisionRequest;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Board must not import Inbox, so the card page calls these functions for its Decisions tab. */
final class CardDecisionsExtension extends AbstractExtension
{
    public function __construct(
        private readonly InboxAvailability $inbox,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ShowCardDecisionsHandler $showDecisions,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_card_decisions', $this->decisions(...)),
            new TwigFunction('inbox_card_decisions_panel', $this->panel(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('inbox_decision_change_form', $this->changeForm(...)),
        ];
    }

    /** The decisions of the card, or null when the tab does not show. One read feeds the tab label and the panel. */
    public function decisions(Card $card): ?ShowCardDecisionsView
    {
        if (!$this->inbox->isEnabled() || !$this->authorization->isGranted(ProjectVoter::VIEW, $card->project)) {
            return null;
        }

        return ($this->showDecisions)(new ShowCardDecisionsCommand($card));
    }

    public function panel(Environment $twig, ShowCardDecisionsView $view): string
    {
        return $twig->render('@Inbox/_card_decisions.html.twig', ['view' => $view, 'offer' => null, 'followUp' => null]);
    }

    public function changeForm(InboxDecision $decision): FormView
    {
        return $this->formFactory->createNamed(
            ChangeInboxDecisionFormType::nameFor($decision),
            ChangeInboxDecisionFormType::class,
            new ChangeInboxDecisionRequest(),
            ['other_options' => $decision->otherOptions],
        )->createView();
    }
}
