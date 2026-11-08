<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\View\ChartBox;
use Sentry\Unit;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Twig\Environment;

/**
 * Draws the preview image of a link, for the worker and for the image address alike. It reads the result itself under
 * the lock of the link, and reads it again after the render, because a keep does not take this lock. It keeps only a
 * PNG whose version the second read confirmed.
 */
final readonly class PublicPreviewDrawer
{
    public const string WORKER = 'worker';
    public const string INLINE = 'inline';

    /** Seconds. */
    private const int LOCK_TTL = 60;
    private const int MAX_DRAWS = 3;

    public function __construct(
        private LockFactory $lockFactory,
        private PublicPreviewSubjects $subjects,
        private PublicPreviewEntries $entries,
        private PublicGraphResults $graphResults,
        private ExperimentPreviewLayouts $experimentLayouts,
        private PreviewRenderer $renderer,
        private Environment $twig,
    ) {
    }

    /**
     * @param string $path self::WORKER or self::INLINE, the attribute of the draw time metric
     *
     * @throws PreviewRenderFailed    when the tool fails
     * @throws PreviewResultUnsettled when the result changes during each draw
     */
    public function draw(PublicLink $link, string $path): PublicPreview
    {
        $lock = $this->lockFactory->createLock('public_preview:'.$link->code, self::LOCK_TTL, false);
        $lock->acquire(true);

        try {
            $startedAt = hrtime(true);
            $subject = $this->read($link);
            $entry = $this->entries->entryFor($link->code);
            $kept = null !== $entry && $entry->version === $subject->version ? $this->entries->png($entry) : null;
            if (null !== $kept) {
                $this->entries->keep($link->code, $subject->version, $kept);

                return new PublicPreview($subject->version, $kept, true);
            }

            $metrics = \Sentry\traceMetrics();
            for ($draw = 1; $draw <= self::MAX_DRAWS; ++$draw) {
                if ($draw > 1) {
                    $metrics->count('public_preview.redraws', 1);
                }
                $png = $this->renderer->render($this->svg($link, $subject));
                $after = $this->read($link);
                if ($after->version !== $subject->version) {
                    $subject = $after;

                    continue;
                }

                $written = $this->stillHeld($lock) ? $this->entries->keep($link->code, $subject->version, $png) : 0;
                if ($written > 0) {
                    $metrics->count('public_preview.bytes_written', $written, [], Unit::byte());
                }
                $metrics->distribution('public_preview.draw_ms', (hrtime(true) - $startedAt) / 1_000_000, ['path' => $path], Unit::millisecond());

                return new PublicPreview($subject->version, $png);
            }

            throw new PreviewResultUnsettled(\sprintf('The result of the link %s changed during each of %d draws.', $link->code, self::MAX_DRAWS));
        } finally {
            $this->release($lock);
        }
    }

    private function read(PublicLink $link): PublicPreviewSubject
    {
        return $this->subjects->read($link) ?? throw new \LogicException('This link has no preview image to draw.');
    }

    private function svg(PublicLink $link, PublicPreviewSubject $subject): string
    {
        if (null !== $subject->experiment) {
            return $this->twig->render('@Insights/public/preview/experiment.svg.twig', [
                'layout' => $this->experimentLayouts->build($subject->experiment),
                'palette' => PreviewPalette::forTemplate(),
            ]);
        }

        $box = ChartBox::preview();
        $kept = $subject->graph;
        $view = null === $kept ? null : $this->graphResults->view($link->project, $kept, $box);
        $state = null === $view ? 'broken' : (null === $view->layout ? 'empty' : 'chart');

        return $this->twig->render('@Insights/public/preview/graph.svg.twig', [
            'state' => $state,
            'view' => $view,
            'legend' => $subject->legend,
            'box' => $box,
            'palette' => PreviewPalette::forTemplate(),
        ]);
    }

    private function stillHeld(SharedLockInterface $lock): bool
    {
        try {
            $lock->refresh();

            return $lock->isAcquired() && !$lock->isExpired();
        } catch (LockException) {
            return false;
        }
    }

    private function release(SharedLockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (LockException) {
            // The lock ran out and another request took it, so there is nothing to release.
        }
    }
}
