<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\CancelInboxActionCommand;
use App\Module\Inbox\Command\CancelInboxActionHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\CancelInboxActionFormType;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxActionFeedback;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxReturnTargetResolver;
use App\Module\Inbox\View\InboxActionVerb;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(InboxItemVoter::ANSWER, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/actions/{actionId}/cancel',
    name: 'app_inbox_item_action_cancel',
    requirements: ['itemId' => Requirement::UUID, 'actionId' => Requirement::UUID],
    methods: ['POST'],
)]
final class CancelInboxActionController extends AppController
{
    public function __construct(
        private readonly CancelInboxActionHandler $cancelAction,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly InboxReturnTargetResolver $returnTargets,
        private readonly InboxActionFeedback $feedback,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item,
        #[MapEntity(expr: 'repository.find(actionId)')] InboxAction $action,
    ): Response {
        $this->inbox->requireEnabled();
        $return = $this->returnTargets->resolve($request, $item);
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in user.');

        $form = $this->formFactory->createNamed(CancelInboxActionFormType::nameFor($action), CancelInboxActionFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->cancelAction)(new CancelInboxActionCommand($action, $item, $user));
                $saved = $this->returnTargets->resolve($request, $item, $return);
                $this->feedback->saved($saved, $item, InboxActionVerb::Cancelled);

                return $this->redirectToRoute($saved->route, $saved->routeParameters);
            } catch (DomainErrors $e) {
                // The form has no field, so every refusal belongs to the form itself.
                foreach ($e->errors as $translationKey) {
                    $form->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward($return->controller, [
            ...$return->attributes,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ], $return->query)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
