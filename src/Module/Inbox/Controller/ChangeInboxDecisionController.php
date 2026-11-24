<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ChangeInboxDecisionCommand;
use App\Module\Inbox\Command\ChangeInboxDecisionHandler;
use App\Module\Inbox\Command\ShowCardDecisionsCommand;
use App\Module\Inbox\Command\ShowCardDecisionsHandler;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Form\ChangeInboxDecisionFormType;
use App\Module\Inbox\Form\ChangeInboxDecisionRequest;
use App\Module\Inbox\Security\InboxDecisionVoter;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/** Records the answer the owner prefers for a decision, and offers a follow-up card when the card is finished. */
#[IsGranted(InboxDecisionVoter::CHANGE, subject: 'decision')]
#[Route(
    '/projects/{projectId}/inbox/decisions/{decisionId}/change',
    name: 'app_inbox_decision_change',
    methods: ['POST'],
)]
final class ChangeInboxDecisionController extends AppController
{
    public function __construct(
        private readonly ChangeInboxDecisionHandler $changeDecision,
        private readonly ShowCardDecisionsHandler $showDecisions,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(decisionId, projectId)')] InboxDecision $decision,
    ): Response {
        $this->inbox->requireEnabled();
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in user.');
        $card = $decision->card;
        $stream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $back = fn (): Response => $this->redirectToRoute('app_board_card', ['projectId' => $decision->project->id, 'cardId' => $card->id, 'tab' => 'decisions']);

        $data = new ChangeInboxDecisionRequest();
        $form = $this->formFactory->createNamed(ChangeInboxDecisionFormType::nameFor($decision), ChangeInboxDecisionFormType::class, $data, ['other_options' => $decision->otherOptions]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $changed = ($this->changeDecision)(new ChangeInboxDecisionCommand($decision, $user, $data->choice($decision->otherOptions), $data->note));
                $message = $this->translator->trans('inbox.decisions.flash.changed');
                if (!$stream) {
                    $this->addFlash('success', $message);

                    return $back();
                }

                return new Response(
                    $this->renderView('@Inbox/_card_decisions.stream.html.twig', [
                        'view' => ($this->showDecisions)(new ShowCardDecisionsCommand($card)),
                        'offer' => $changed->offersFollowUp ? $changed->decision : null,
                        'followUp' => null,
                    ]).$this->renderView('_flash.stream.html.twig', ['severity' => 'success', 'message' => $message]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $target = $form->has($field) ? $form->get($field) : $form;
                    $target->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        if (!$stream) {
            $this->addFlash('error', $this->translator->trans('inbox.decisions.flash.not_changed'));

            return $back();
        }

        return new Response(
            $this->renderView('@Inbox/_card_decision_change_form.stream.html.twig', ['decision' => $decision, 'form' => $form->createView()]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
