<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubRulesetFile;

/** Builds the import file of one repository from its stored check. */
final readonly class DownloadRepositoryRulesetHandler
{
    public function __construct(
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private GitHubRulesetFile $rulesetFile,
    ) {
    }

    /** @return string|null the JSON of the file, null when no check is stored or no rule would be in the file */
    public function __invoke(DownloadRepositoryRulesetCommand $command): ?string
    {
        $check = $this->forgeRepositoryChecks->findOneOfRepository($command->repository);
        if (null === $check) {
            return null;
        }

        $settings = null;
        if (null !== $check->settings) {
            try {
                $settings = RepositorySettings::fromArray($check->settings);
            } catch (\InvalidArgumentException) {
                $settings = null;
            }
        }

        return $this->rulesetFile->json($settings, array_map(static fn (array $item): string => $item['key'], $check->items));
    }
}
