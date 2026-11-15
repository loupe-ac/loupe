<?php

declare(strict_types=1);

namespace App\Module\Account\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    }
}
