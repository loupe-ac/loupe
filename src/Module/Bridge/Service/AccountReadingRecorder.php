<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Stores each reading of a bridge account that differs from the latest one of its window. The caller flushes. */
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

        $latest = [];
        foreach ($this->bridgeAccountReadings->latestOfBridge($bridge->owner->id ?? throw new \LogicException('An authenticated user always has an id.'), $bridge->id) as $stored) {
            $latest[$stored->account."\0".$stored->window] = $stored;
        }

        usort($reports, static fn (AccountReadingReport $a, AccountReadingReport $b): int => $a->readAt <=> $b->readAt);
        foreach ($reports as $report) {
            $key = $report->account."\0".$report->window;
            if (isset($latest[$key]) && $latest[$key]->says($report)) {
                continue;
            }

            $latest[$key] = $report;
            $this->em->persist(new BridgeAccountReading(Uuid::v7(), $bridge, $report->account, $report->window, $report->usedPercent, $report->resetsAt, $report->limited, $report->source, $report->readAt));
            $changed = true;
        }

        return $changed;
    }
}
