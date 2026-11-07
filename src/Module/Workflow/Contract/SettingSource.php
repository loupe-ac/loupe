<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Where the resolved value of a workflow setting came from. */
enum SettingSource: string
{
    /** A valid value in `.loupe.yaml`. */
    case File = 'file';
    /** The file holds a value that is not allowed, so the key reads as unset. */
    case Invalid = 'invalid';
    /** A value that the app stores for one repository. */
    case Stored = 'stored';
    /** The default that the template declares. */
    case Default = 'default';
    /** No source states a value and the template has no default. */
    case Unset = 'unset';
    /** Two repositories of the project state different valid values. */
    case Conflict = 'conflict';
    /** The value in the template copy of the project. */
    case Template = 'template';
}
