<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\RepositoryRef;
use App\Module\Workflow\Contract\SettingScope;
use App\Module\Workflow\Contract\SettingSource;

/** The resolved value of one workflow setting, for one repository or for the whole project. */
final readonly class WorkflowSettingView
{
    /**
     * @param ?RepositoryRef $repository the repository of a repository setting, or null for a project setting
     * @param list<string>   $warnings
     */
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public ?RepositoryRef $repository,
        public string|bool|null $value,
        public SettingSource $source,
        public array $warnings = [],
    ) {
    }
}
