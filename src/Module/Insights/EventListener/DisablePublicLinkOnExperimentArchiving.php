<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\ExperimentArchiving;
use App\Module\Insights\Repository\PublicLinkRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Switches the public link of an experiment off when the experiment is archived, inside the transaction of the
 * archive. A return to the list, by a new run or by hand, leaves the link off.
 */
#[AsEventListener]
final readonly class DisablePublicLinkOnExperimentArchiving
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
    ) {
    }

    public function __invoke(ExperimentArchiving $event): void
    {
        $link = $this->publicLinks->findForExperiment($event->project, $event->experiment);
        if (null !== $link) {
            $link->enabled = false;
        }
    }
}
