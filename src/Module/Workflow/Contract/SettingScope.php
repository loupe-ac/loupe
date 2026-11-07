<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a setting value applies to: one repository, or the whole project. */
enum SettingScope: string
{
    case Repository = 'repository';
    case Project = 'project';
}
