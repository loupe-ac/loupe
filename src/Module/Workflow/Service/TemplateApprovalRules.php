<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\ApprovalRules;
use App\Module\Workflow\Contract\ApprovalRuleWriter;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateMissing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the approval rule from the stored template copy, and writes it back to that copy. A project bound to no template lets an approval cover a conflict resolution. */
#[AsAlias(ApprovalRules::class)]
#[AsAlias(ApprovalRuleWriter::class)]
final readonly class TemplateApprovalRules implements ApprovalRules, ApprovalRuleWriter
{
    public const string KEY = 'approvalCoversConflictResolution';

    public function __construct(
        private ProjectTemplateCopy $templates,
        private WorkflowBindingRepository $workflowBindings,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function coversConflictResolution(Uuid $projectId): bool
    {
        try {
            return $this->templates->forProject($projectId)->approvalCoversConflictResolution;
        } catch (TemplateMissing) {
            return true;
        }
    }

    #[\Override]
    public function setCoversConflictResolution(Uuid $projectId, bool $value): bool
    {
        $binding = $this->workflowBindings->findOneByProjectId($projectId);
        if (null === $binding) {
            return false;
        }
        $binding->definition[self::KEY] = $value;
        $this->em->flush();
        $this->templates->reset();

        return true;
    }
}
