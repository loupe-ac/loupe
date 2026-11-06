<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;

/** Turns what the experiment handlers refuse with into the coded problems of the public API. */
final readonly class ExperimentApiProblems
{
    public function experimentNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'experiment_not_found', 'Experiment not found', 'This project has no experiment with this name.'));
    }

    public function cardNotInExperiment(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'card_not_in_experiment', 'Card not in experiment', 'The experiment does not list a card with this number.'));
    }

    /** A refusal that no code covers stays as it is, so it is logged as a failure. */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        return match (array_first($errors->errors)) {
            'bridge.experiments.exclusion.error.unknown_experiment' => $this->experimentNotFound(),
            'bridge.experiments.exclusion.error.card_not_in_experiment' => $this->cardNotInExperiment(),
            default => $errors,
        };
    }
}
