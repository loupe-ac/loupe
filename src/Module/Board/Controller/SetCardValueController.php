<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\SetCardValueFormType;
use App\Module\Board\Form\SetCardValueRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\View\CardValueView;
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
    '/projects/{projectId}/board/cards/{cardId}/values/{key}',
    name: 'app_board_card_value',
    requirements: ['cardId' => Requirement::UUID, 'key' => '[a-z0-9-]+'],
    methods: ['POST'],
)]
final class SetCardValueController extends AppController
{
    private const string NOT_SAVED = 'board.card.values.error.not_saved';

    public function __construct(
        private readonly UpdateCardHandler $updateCard,
        private readonly CardValueReader $values,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        string $key,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        if (!array_any($this->values->forCard($card), static fn (CardValueView $view): bool => $view->key === $key)) {
            throw $this->createNotFoundException();
        }

        $data = new SetCardValueRequest();
        $form = $this->formFactory->createNamed(SetCardValueFormType::nameFor((string) $card->id, $key), SetCardValueFormType::class, $data);
        $form->handleRequest($request);

        $refusal = self::NOT_SAVED;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateCard)(new UpdateCardCommand($card, Actor::Human, values: [$key => $data->value]));
                $refusal = null;
            } catch (DomainErrors) {
                $refusal = self::NOT_SAVED;
            }
        }

        $current = array_find($this->values->forCard($card), static fn (CardValueView $view): bool => $view->key === $key);
        if (null !== $current && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                $this->renderView('@Board/_card_value.stream.html.twig', [
                    'card' => $card,
                    'value' => $current,
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
