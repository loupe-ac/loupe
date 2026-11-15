<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

readonly class ExpiredExportPurger
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private AccountChangedPublisher $accountChanged,

        #[Target('export.storage')]
        private FilesystemOperator $exportStorage,
    ) {
    }

    /** Deletes each expired export's archive (if present) and row. Returns the purge count. */
    public function purge(): int
    {
        $expired = $this->dataExports->findExpired();

        $purged = 0;
        $owners = [];
        foreach ($expired as $export) {
            $exportId = $export->id;
            if (null !== $exportId) {
                // Flysystem treats a missing object as already deleted. A real
                // failure (permissions, an unreachable bucket) must NOT remove
                // the row — that would orphan the archive with nothing left to
                // find and retry it on the next run.
                try {
                    $this->exportStorage->delete(DataExport::computeArchiveKey($exportId));
                } catch (FilesystemException) {
                    $this->logger->warning('account.data_export_purge_unlink_failed', ['id' => (string) $exportId]);

                    continue;
                }
            }

            $ownerId = $export->user->id ?? throw new \LogicException('a persisted user always has an id');
            $owners[$ownerId->toRfc4122()] = $ownerId;
            $this->em->remove($export);
            ++$purged;
        }

        $this->em->flush();
        foreach ($owners as $ownerId) {
            $this->accountChanged->changed($ownerId, AccountArea::EXPORTS);
        }

        // The one completion line for a purge, whichever caller ran it: the
        // hourly scheduler tick, the console command, or an export's own
        // cleanup. Each caller must stay silent, or a tick logs twice.
        $this->logger->info('account.data_export_purge_completed', ['purged' => $purged]);

        return $purged;
    }
}
