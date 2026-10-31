<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stores each reading of a bridge account that differs from the latest one of its window, and moves the last seen
 * time of a reading that the bridge reports again. The caller flushes.
 */
final readonly class AccountReadingRecorder
{
    public function __construct(
        private BridgeAccountReadingRepository $bridgeAccountReadings,
        private EntityManagerInterface $em,
        private TryNowOverrides $tryNowOverrides,
    ) {
    }

    /**
     * @param list<AccountReadingReport> $reports
     *
     * @return bool whether a reading was stored or a Try now override closed
     */
    public function record(Bridge $bridge, array $reports): bool
    {
        if ([] === $reports) {
            return false;
        }

        $changed = $this->tryNowOverrides->closeOnReadings($bridge, $reports);

        $ownerId = $bridge->owner->id ?? throw new \LogicException('An authenticated user always has an id.');
        $latest = [];
        foreach ($this->bridgeAccountReadings->latestOfBridge($ownerId, $bridge->id) as $stored) {
            $latest[$stored->account."\0".$stored->window] = $stored;
        }

        /** @var array<string, BridgeAccountReading> $added */
        $added = [];
        usort($reports, static fn (AccountReadingReport $a, AccountReadingReport $b): int => $a->readAt <=> $b->readAt);
        foreach ($reports as $report) {
            $key = $report->account."\0".$report->window;
            if (isset($latest[$key]) && $latest[$key]->says($report)) {
                if (isset($added[$key])) {
                    $added[$key]->seenAt = max($added[$key]->seenAt, $report->readAt);
                } else {
                    $this->bridgeAccountReadings->markSeen($ownerId, $bridge->id, $report->account, $report->window, $report->readAt);
                }

                continue;
            }

            $latest[$key] = $report;
            $added[$key] = new BridgeAccountReading(Uuid::v7(), $bridge, $report->account, $report->window, $report->usedPercent, $report->resetsAt, $report->limited, $report->source, $report->readAt);
            $this->em->persist($added[$key]);
            $changed = true;
        }

        return $changed;
    }
}
