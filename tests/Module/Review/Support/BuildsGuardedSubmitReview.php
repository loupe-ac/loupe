<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Support;

use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Contract\VerdictGuardInterface;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\DecisionSummaryReader;
use App\Module\Review\Service\DocumentLiveSignals;
use App\Module\Review\Service\DraftStateSignal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;

/** A verdict handler built from the services of the container, with the guards that a test names. */
trait BuildsGuardedSubmitReview
{
    private function guardedSubmitReview(VerdictGuardInterface ...$guards): SubmitReviewHandler
    {
        $events = static::getContainer()->get('event_dispatcher');

        return new SubmitReviewHandler(
            $this->guardedService(EntityManagerInterface::class),
            $this->guardedService(DocumentRepository::class),
            $this->guardedService(DocumentVersionRepository::class),
            $this->guardedService(CommentRepository::class),
            $this->guardedService(ReviewRepository::class),
            $events instanceof EventDispatcherInterface ? $events : throw new \LogicException('No event dispatcher.'),
            $this->guardedService(DraftStateSignal::class),
            $this->guardedService(DocumentLiveSignals::class),
            $this->guardedService(DecisionSummaryReader::class),
            $this->guardedService(Auditor::class),
            $guards,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function guardedService(string $class): object
    {
        $found = static::getContainer()->get($class);

        return $found instanceof $class ? $found : throw new \LogicException('No service '.$class);
    }
}
