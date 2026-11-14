<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

use App\Module\Project\Entity\Project;
use Symfony\Component\Validator\Constraints as Assert;

final class ChooseInstallProjectRequest
{
    public const string NEW_PROJECT = 'new';

    public function __construct(
        /** The id of an owned project, or `new`. A missing value also means a new project. */
        public ?string $project = self::NEW_PROJECT,

        #[Assert\Length(max: Project::MAX_NAME_LENGTH, normalizer: 'trim')]
        public ?string $name = null,
    ) {
    }

    public function projectId(): ?string
    {
        return self::NEW_PROJECT === $this->project ? null : $this->project;
    }
}
