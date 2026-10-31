<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Dev;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Form\RunInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Security\InboxItemVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A preview of a late answer: the inbox page as the refused answer to a closed question renders it.
 * A signed link opens it with a GET, so a reviewer sees the refusal and the kept draft.
 */
#[IsGranted(InboxItemVoter::ANSWER, subject: 'item')]
#[Route(
    '/dev/preview/projects/{projectId}/inbox/items/{itemId}/late-answer',
    name: 'app_dev_preview_inbox_late_answer',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewLateAnswerController extends AppController
{
    private const array DRAFT = ['selectedOptions' => '0', 'note' => 'Go ahead with this option.'];

    public function __construct(
        private readonly RunInboxActionHandler $runAction,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(#[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item): Response
    {
        // An open item would take the answer, so only a closed question is safe to preview.
        if (InboxItemState::Open === $item->state || InboxItemKind::Question !== $item->kind) {
            throw new NotFoundHttpException('The late answer preview needs a closed question.');
        }
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in user.');

        $form = $this->formFactory->createNamed(RunInboxActionFormType::responseNameFor($item), RunInboxActionFormType::class, new RunInboxActionRequest(), ['csrf_protection' => false]);
        $form->submit(self::DRAFT, false);
        try {
            ($this->runAction)(new RunInboxActionCommand(item: $item, kind: InboxActionKind::Answer, note: self::DRAFT['note'], user: $user, selectedOptions: self::DRAFT['selectedOptions']));
            throw new \LogicException('A closed question took an answer.');
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $key) {
                ($form->has($field) ? $form->get($field) : $form)->addError(new FormError($this->translator->trans($key, $e->parameters[$field] ?? [])));
            }
        }

        return $this->forward(ShowInboxController::class, [
            'id' => (string) $item->project->id,
            'project' => $item->project,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ], ['item' => $item->number]);
    }
}
