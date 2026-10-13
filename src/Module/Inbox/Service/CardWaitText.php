<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the translation key, the values and the link for one wait. Each type has the keys of the part that owns it. */
final readonly class CardWaitText
{
    public function __construct(
        private DocumentRepository $documents,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkerRunRepository $workerRuns,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    /** A stop pause of the card is the action of the run line that caused it, so the page shows it once. */
    public static function isFolded(InboxCardWait $wait): bool
    {
        return null === $wait->endedAt
            && InboxCardWaitType::CardPause === $wait->type
            && \in_array($wait->reason, [InboxCardWaitReason::PauseWorkStopped, InboxCardWaitReason::PauseRetries], true)
            && null !== self::openRunWait(array_values($wait->watch->waits->toArray()));
    }

    /**
     * The title of the item: the reason of the wait that matters most, or the card title when no wait is open.
     * The inbox row and the linked panel name the card, so the title leaves the card out.
     *
     * @param list<InboxCardWait> $open
     */
    public function title(array $open, string $cardTitle, int $maxLength): string
    {
        $main = self::mainWait($open);
        $line = null === $main ? null : $this->line($main, false);
        $reason = null === $line ? '' : $this->translator->trans($line->titleKey(), $line->params, null, $this->locale);

        return mb_substr('' === $reason ? $cardTitle : $reason, 0, $maxLength);
    }

    /**
     * One sentence for each wait the page shows, with its action.
     *
     * @param list<InboxCardWait> $open
     */
    public function body(array $open): string
    {
        $lines = [];
        foreach ($open as $wait) {
            if (self::isFolded($wait)) {
                continue;
            }
            $line = $this->line($wait, false);
            $lines[] = rtrim($this->translator->trans($line->key, $line->params, null, $this->locale), '.').'. '.$this->translator->trans($line->actionKey(), $line->params, null, $this->locale).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<InboxCardWait> $open
     */
    private static function mainWait(array $open): ?InboxCardWait
    {
        return self::openRunWait($open) ?? array_first(array_filter($open, static fn (InboxCardWait $wait): bool => !self::isFolded($wait))) ?? null;
    }

    /** @param list<InboxCardWait> $waits */
    private static function openRunWait(array $waits): ?InboxCardWait
    {
        foreach ($waits as $wait) {
            if (null === $wait->endedAt && InboxCardWaitType::WorkerRun === $wait->type) {
                return $wait;
            }
        }

        return null;
    }

    public function line(InboxCardWait $wait, bool $documentLinked): CardWaitLine
    {
        $projectId = $wait->watch->project->id;
        $params = ['%card%' => $wait->watch->cardNumber];
        $href = null;
        $detail = null;
        $key = null;
        $titleKey = null;
        $pageKey = null;
        $action = null;

        switch ($wait->type) {
            case InboxCardWaitType::Document:
                $document = null === $wait->documentId ? null : $this->documents->find($wait->documentId);
                $params['%title%'] = $document->title ?? '';
                $params['%version%'] = $wait->versionNumber ?? 0;
                if (null !== $wait->documentId && null === $document) {
                    $key = 'review.inbox_wait.document_deleted';
                    break;
                }
                if ($documentLinked && null !== $wait->documentId) {
                    $href = $this->urls->generate('app_document_review', ['projectId' => $projectId, 'documentId' => $wait->documentId]);
                }
                break;
            case InboxCardWaitType::PullRequest:
                $pullRequest = null === $wait->pullRequestId ? null : $this->forgePullRequests->find($wait->pullRequestId);
                $params['%number%'] = $pullRequest->number ?? 0;
                $href = null === $pullRequest ? null : \sprintf('https://github.com/%s/pull/%d', $pullRequest->repository, $pullRequest->number);
                break;
            case InboxCardWaitType::WorkerRun:
                $run = null === $wait->runId ? null : $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $projectId);
                $href = null === $wait->runId ? null : $this->urls->generate('app_project_worker_runs', ['id' => $projectId, 'search' => $wait->runId]);
                $headline = null === $run ? '' : RunOutputText::headline($run->output);
                $detail = null === $run ? null : RunOutputText::clean($run->output);
                $worker = null === $run || null === $run->workKind || '' === $run->workKind ? 'worker' : str_replace('-', ' ', $run->workKind).' worker';
                $params['%worker%'] = $worker;
                $params['%Worker%'] = ucfirst($worker);
                $params['%headline%'] = $headline;
                if ('' !== $headline) {
                    $base = 'bridge.inbox_wait.'.$wait->reason->keyPart();
                    $key = $base.'.headline';
                    $titleKey = $key.'.title';
                    $pageKey = $base.'.short';
                    $action = $base.'.action';
                }
                if (self::hasFoldedPause($wait)) {
                    $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                    $action = 'bridge.inbox_wait.folded.action';
                }
                break;
            case InboxCardWaitType::CardPause:
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                break;
        }

        return new CardWaitLine($key ?? self::prefix($wait->type).'.inbox_wait.'.$wait->reason->keyPart(), $params, $href, '' === $detail ? null : $detail, $titleKey, $action, $pageKey);
    }

    private static function hasFoldedPause(InboxCardWait $run): bool
    {
        if (null !== $run->endedAt) {
            return false;
        }
        foreach ($run->watch->waits as $wait) {
            if (self::isFolded($wait)) {
                return true;
            }
        }

        return false;
    }

    private static function prefix(InboxCardWaitType $type): string
    {
        return match ($type) {
            InboxCardWaitType::Document => 'review',
            InboxCardWaitType::PullRequest => 'forge',
            InboxCardWaitType::WorkerRun => 'bridge',
            InboxCardWaitType::CardPause => 'board',
        };
    }
}
