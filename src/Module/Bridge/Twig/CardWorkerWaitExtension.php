<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Form\ChangeWaitLimitFormType;
use App\Module\Bridge\Form\ChangeWaitLimitRequest;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\View\CardWorkerWait;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\WaitConditions;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The card page shows the open wait of its worker through this function, because no module may import Board:
 * a wait knows its card by id, never by entity.
 */
final class CardWorkerWaitExtension extends AbstractExtension
{
    public function __construct(
        private readonly WorkerWaitRepository $workerWaits,
        private readonly WaitConditions $waitConditions,
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('card_worker_wait', $this->cardWorkerWait(...))];
    }

    public function cardWorkerWait(Project $project, string $cardId): ?CardWorkerWait
    {
        if (!Uuid::isValid($cardId)) {
            return null;
        }
        $wait = $this->workerWaits->findOpenOfCard(Uuid::fromString($cardId));
        if (null === $wait || !$wait->project->requireId()->equals($project->requireId())) {
            return null;
        }

        $minutes = intdiv($wait->limitAt->getTimestamp() - $wait->openedAt->getTimestamp(), 60);
        $form = $this->forms->create(
            ChangeWaitLimitFormType::class,
            new ChangeWaitLimitRequest($minutes),
            [
                'action' => $this->urls->generate('app_project_card_wait_limit', ['id' => (string) $project->id, 'cardId' => $cardId]),
                'max_minutes' => $this->waitConditions->settingsOf($project->requireId())->maxMinutes,
            ],
        );

        return new CardWorkerWait($wait->openedAt, $wait->limitAt, $minutes, $form->createView());
    }
}
