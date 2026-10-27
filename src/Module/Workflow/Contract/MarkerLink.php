<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The page a marker links to under its remedy. */
enum MarkerLink: string
{
    case Agents = 'agents';
    case RulesetSetup = 'ruleset-setup';
    case Repositories = 'repositories';

    /** The translation key of the link text. */
    public function translationKey(): string
    {
        return 'marker.link.'.$this->value;
    }
}
