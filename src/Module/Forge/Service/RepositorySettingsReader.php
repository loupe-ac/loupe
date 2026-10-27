<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Settings\RepositorySettings;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Reads the settings of a repository that bear on the workflow from one forge. A forge module implements it. */
#[AutoconfigureTag('app.repository_settings_reader')]
interface RepositorySettingsReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** @throws RepositorySettingsUnreadable on any failed or unclear read */
    public function read(ForgeRepository $repository): RepositorySettings;
}
