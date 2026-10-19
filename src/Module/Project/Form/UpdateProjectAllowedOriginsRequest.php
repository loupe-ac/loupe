<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\SiteOrigins;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectAllowedOriginsRequest
{
    public function __construct(
        #[Assert\Length(max: SiteOrigins::MAX_TEXT_LENGTH)]
        public ?string $origins = null,
    ) {
    }

    /** A project with a website and no origin yet starts with its website, which nothing saves until the owner submits. */
    public static function fromProject(Project $project): self
    {
        if ([] === $project->allowedOrigins && null !== $project->domain) {
            $website = SiteOrigins::normalise($project->domain) ?? SiteOrigins::normalise('https://'.$project->domain);

            return new self($website);
        }

        return new self(implode("\n", $project->allowedOrigins));
    }
}
