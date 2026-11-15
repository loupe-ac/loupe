<?php

declare(strict_types=1);

namespace App\Module\Account\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The state of the data page with an export row: the keys account holds one
 * ready export. The empty twin is the keys-empty account, which holds none.
 */
#[When('dev')]
#[When('test')]
final readonly class DevDataExportSeeder implements DevSeederInterface
{
    public const string KEY = 'data-exports';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,

        #[Target('export.storage')]
        private FilesystemOperator $exportStorage,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $id = DevSeedId::of('account/data-export/keys');
        if ($this->em->find(DataExport::class, $id) instanceof DataExport) {
            return;
        }

        $owner = $this->users->find(DevSeedId::of(DevAccountSeeder::KEYS_USER))
            ?? throw new \LogicException('The keys account does not exist.');
        $export = new DataExport($owner, $context->at(7200));
        $export->complete();
        $context->ids->assign($export, $id);
        $this->em->persist($export);
        $this->em->flush();
        $this->exportStorage->write(DataExport::computeArchiveKey($id), $this->emptyArchive());
    }

    private function emptyArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loupe-seed-export-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file for the seeded archive.');
        }

        try {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('README.txt', 'Seeded data export.');
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
