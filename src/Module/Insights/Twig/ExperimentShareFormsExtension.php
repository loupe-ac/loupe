<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Form\ShareExperimentRequest;
use App\Module\Insights\Service\ExperimentShareForms;
use App\Module\Insights\Service\ExperimentShareStates;
use App\Module\Insights\View\PublicLinkShareView;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The share form, the share state and the DOM key of experiments. */
final class ExperimentShareFormsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExperimentShareForms $forms,
        private readonly ExperimentShareStates $states,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('experiment_share_form', $this->shareForm(...)),
            new TwigFunction('experiment_share_state', $this->states->for(...)),
            new TwigFunction('experiment_share_states', $this->states->forExperiments(...)),
            new TwigFunction('experiment_share_key', ExperimentShareForms::keyOf(...)),
        ];
    }

    /** The switch and the note of the share dialog. The switch is disabled while a reason blocks the share. */
    public function shareForm(Project $project, string $experiment, PublicLinkShareView $share): FormView
    {
        $data = ShareExperimentRequest::fromState($share->isEnabled(), $share->link?->note);

        return $this->forms->share($project, $experiment, $data, null !== $share->block)->createView();
    }
}
