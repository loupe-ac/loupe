<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Tells whether a project is connected to one forge, and builds the link of a pull request on it. A forge module implements it. */
#[AutoconfigureTag('app.forge_connection_reader')]
interface ForgeConnectionReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** Whether the forge has a repository at this path. GitHub has two segments, and GitLab can have more. */
    public function acceptsPath(string $path): bool;

    /** Whether the project has an installation or a watched repository on this forge. */
    public function isConnected(Project $project): bool;

    /**
     * @throws \InvalidArgumentException when the reader does not accept the path
     */
    public function pullRequestUrl(string $path, int $number): string;
}
