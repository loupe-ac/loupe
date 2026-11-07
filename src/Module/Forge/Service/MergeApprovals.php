<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Workflow\Contract\SettingSource;
use App\Module\Workflow\Contract\WorkflowSettings;

/** Reads the merge question of a repository from the workflow setting and from its stored rules read. */
final readonly class MergeApprovals
{
    public const string SETTING = 'merge-approval';
    public const string PERSON = 'person';
    public const string AGENT = 'agent';

    public function __construct(
        private WorkflowSettings $settings,
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
    ) {
    }

    public function of(ForgeRepository $repository): MergeApproval
    {
        $projectId = $repository->project->requireId();
        if (!$this->settings->declares($projectId, self::SETTING)) {
            return new MergeApproval(false, false, false, false, false);
        }

        $resolved = $this->settings->forRepository($projectId, $repository->id ?? throw new \LogicException('A stored repository has an id.'), self::SETTING);
        $rules = $this->forgeRepositoryChecks->findOneOfRepository($repository)?->settings['rules'] ?? null;

        return new MergeApproval(
            true,
            self::PERSON === $resolved->value,
            null === $resolved->value,
            SettingSource::Invalid === $resolved->source,
            \is_array($rules) && true === ($rules['requiresReview'] ?? null),
        );
    }
}
