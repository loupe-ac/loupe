<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\SetCardHumanReviewFormType;
use App\Module\Board\Form\SetCardHumanReviewRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Workflow\Contract\Actor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/human-review',
    name: 'app_board_card_human_review',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SetCardHumanReviewController extends AppController
{
    public function __construct(
        private readonly UpdateCardHandler $updateCard,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $data = new SetCardHumanReviewRequest();
        $form = $this->formFactory->createNamed(SetCardHumanReviewFormType::nameFor((string) $card->id), SetCardHumanReviewFormType::class, $data);
        $form->handleRequest($request);

        $refusal = 'board.card.human_review.error.not_saved';
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateCard)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: $data->requiresHumanReview));
                $refusal = null;
            } catch (DomainErrors $e) {
                $refusal = array_first($e->errors);
            }
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                $this->renderView('@Board/_card_human_review.stream.html.twig', [
                    'card' => $card,
                    'error' => null === $refusal ? null : $this->translator->trans($refusal),
                ]),
                null === $refusal ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        if (null !== $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal));
        }

        return $this->redirectToRoute('app_board_card', ['projectId' => (string) $card->project->id, 'cardId' => (string) $card->id, 'tab' => 'details']);
    }
}
