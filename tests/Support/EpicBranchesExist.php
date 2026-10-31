<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Forge\Service\BranchReader;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;

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
        self::getContainer()->set(BranchReaders::class, new BranchReaders([new class implements BranchReader {
            #[\Override]
            public function exists(Project $project, string $remoteUrl, string $branch): bool
            {
                return true;
            }
        }]));
    }
}
