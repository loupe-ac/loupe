<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use App\Module\Project\Workshop\WorkshopReadinessRow;
use App\Module\Readiness\Command\ReadinessView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The readiness checks of a project, the latest discovery run and whether the Workshop shows the guide. */
#[OA\Schema(required: ['guideShown', 'rows', 'discovery'])]
final readonly class ReadinessResponse
{
    /** @param list<ReadinessRowResponse> $rows */
    public function __construct(
        #[OA\Property(description: 'Whether the Workshop shows the readiness guide. The checks below show in either case.')]
        public bool $guideShown,

        #[OA\Property(description: 'The checks: whether an agent connected, a bridge runs, the GitHub App is installed, the agents push as their own GitHub account, discovery ran, and the optional review widget has a site that may sign in.', type: 'array', items: new OA\Items(ref: new Model(type: ReadinessRowResponse::class)))]
        public array $rows,

        #[OA\Property(ref: new Model(type: ReadinessDiscoveryResponse::class), description: 'The latest discovery run. Null when discovery never ran.', nullable: true)]
        public ?ReadinessDiscoveryResponse $discovery,
    ) {
    }

    public static function fromView(ReadinessView $view, TranslatorInterface $translator): self
    {
        $run = $view->discovery;

        return new self(
            !$view->guideHidden,
            array_map(static fn (WorkshopReadinessRow $row): ReadinessRowResponse => new ReadinessRowResponse(
                $row->key,
                $row->done,
                null !== $row->detail ? $translator->trans($row->detail) : $translator->trans($row->status, $row->statusParameters),
            ), $view->readiness->rows),
            null === $run ? null : new ReadinessDiscoveryResponse(
                (string) $run->id,
                $run->state->value,
                (string) $run->card->id,
                $run->card->number,
                $run->failureReason,
                $run->createdAt->format(\DATE_ATOM),
            ),
        );
    }
}
