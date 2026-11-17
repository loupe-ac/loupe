<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReader;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * For a KernelTestCase that moves an epic child out of Next. The child waits there until a live bridge
 * reports a repository that holds the epic branch. Call it right after booting the kernel.
 */
trait EpicBranchesExist
{
    private function holdEpicBranchesAsExisting(): void
    {
        self::getContainer()->set(WorkshopConnectionsProviderInterface::class, new class implements WorkshopConnectionsProviderInterface {
            #[\Override]
            public function forProject(Project $project): array
            {
                return [new WorkshopConnection('bridge', '1.0.0', false, '/agents', 'bridge', repositoryPath: '/work/loupe', repositoryRemoteUrl: 'https://github.com/acme/loupe.git')];
            }

            #[\Override]
            public function forOwnerOf(Project $project): array
            {
                return [];
            }
        });
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::getContainer()->set(BranchReaders::class, new BranchReaders([new class($em) implements BranchReader {
            /** @var array<string, ForgeRepository> */
            private array $rows = [];

            public function __construct(
                private readonly EntityManagerInterface $em,
            ) {
            }

            #[\Override]
            public function readableRepository(Project $project, string $remoteUrl): ForgeRepository
            {
                $key = (string) $project->id;
                if (!isset($this->rows[$key])) {
                    $this->rows[$key] = new ForgeRepository($project, 'github', '9'.\count($this->rows), 'acme/loupe', ForgeRepositorySource::Installation, '1');
                    $this->em->persist($this->rows[$key]);
                    $this->em->flush();
                }

                return $this->rows[$key];
            }

            #[\Override]
            public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository
            {
                return null;
            }

            #[\Override]
            public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence
            {
                return BranchPresence::Exists;
            }
        }]));
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::getContainer()->set(ForgeBranchPresence::class, new ForgeBranchPresence($connection, $bus, new ExistingBranchesPool(), $clock));
    }
}
