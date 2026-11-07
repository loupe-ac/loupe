<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Form\ShareExperimentFormType;
use App\Module\Insights\Form\ShareExperimentRequest;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The share form of one experiment. Each experiment has its own form name, so the rows of the list share no DOM id,
 * and the controller that receives a post builds the same form under the same name.
 */
final readonly class ExperimentShareForms
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** A short token of the name, safe in a form name and a DOM id. */
    public static function keyOf(string $experiment): string
    {
        return substr(hash('sha256', $experiment), 0, 12);
    }

    /** @return FormInterface<ShareExperimentRequest> */
    public function share(Project $project, string $experiment, ?ShareExperimentRequest $data = null, bool $disabled = false): FormInterface
    {
        return $this->forms->createNamed(
            'share_experiment_'.self::keyOf($experiment),
            ShareExperimentFormType::class,
            $data ?? new ShareExperimentRequest(),
            [
                'action' => $this->urls->generate('app_project_analytics_experiment_share', ['id' => (string) $project->requireId(), 'experiment' => $experiment]),
                'disabled' => $disabled,
            ],
        );
    }
}
