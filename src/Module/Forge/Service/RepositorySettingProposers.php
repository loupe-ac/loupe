<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RepositorySettingProposers
{
    /** @param iterable<RepositorySettingProposer> $proposers */
    public function __construct(
        #[AutowireIterator('app.repository_setting_proposer')]
        private iterable $proposers,
    ) {
    }

    public function for(string $forge): ?RepositorySettingProposer
    {
        foreach ($this->proposers as $proposer) {
            if ($proposer->supports($forge)) {
                return $proposer;
            }
        }

        return null;
    }
}
