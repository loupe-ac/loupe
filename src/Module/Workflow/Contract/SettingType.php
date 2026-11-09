<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The kind of value a workflow setting holds. */
enum SettingType: string
{
    case Boolean = 'bool';
    case Text = 'string';
    case Choice = 'enum';
    case StringList = 'list';
}
