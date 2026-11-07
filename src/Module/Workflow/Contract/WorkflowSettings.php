<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Resolves the value of a workflow setting from `.loupe.yaml`, the stored value, the template copy and the template default. A key that the template does not declare reads as unset. */
interface WorkflowSettings
{
    /** Whether the template of the project declares the key. */
    public function declares(Uuid $projectId, string $key): bool;

    /**
     * @throws \LogicException when the template declares the key with the project scope
     */
    public function forRepository(Uuid $projectId, Uuid $repositoryId, string $key): ResolvedSetting;

    /**
     * @throws \LogicException when the template declares the key with the repository scope
     */
    public function forProject(Uuid $projectId, string $key): ResolvedSetting;

    /** @return list<string> one warning for each project setting that the repositories of the project set to different valid values, limited to the settings that the given repository sets */
    public function projectConflicts(Uuid $projectId, Uuid $repositoryId): array;
}
