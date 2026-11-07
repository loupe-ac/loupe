<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Proposes a change to one `workflow:` key of `.loupe.yaml` as a pull request on a repository.
 * A forge module implements it. An open pull request for the same change counts as proposed, so a retry opens no second one.
 */
#[AutoconfigureTag('app.repository_setting_proposer')]
interface RepositorySettingProposer
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @return string the address of the pull request
     *
     * @throws PullRequestWriteFailed when the forge does not take the change
     */
    public function propose(ForgeRepository $repository, string $key, string $value, string $title, string $body): string;
}
