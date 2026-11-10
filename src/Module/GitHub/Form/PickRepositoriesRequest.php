<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

use App\Module\GitHub\GitHubRepositoryPick;
use Symfony\Component\Validator\Constraints as Assert;

class PickRepositoriesRequest
{
    /**
     * @param list<string> $repositories the posted picks, `installation:repository:path`
     */
    public function __construct(
        #[Assert\All([new Assert\Regex(pattern: '#^\d+:\d+:[^\s:]+$#', message: 'github.form.pick_repositories_form.repositories.invalid')])]
        #[Assert\Count(min: 1, minMessage: 'github.form.pick_repositories_form.repositories.min')]
        public array $repositories = [],
    ) {
    }

    /** @return list<GitHubRepositoryPick> the picks that parse, one for each repository */
    public function picks(): array
    {
        $picks = [];
        foreach ($this->repositories as $value) {
            $pick = GitHubRepositoryPick::parse($value);
            if (null !== $pick) {
                $picks[$pick->installationId.':'.$pick->repositoryId] = $pick;
            }
        }

        return array_values($picks);
    }
}
