<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Api\Problem\ApiProblemException;
use App\Module\Project\Security\ReachableProjectValueResolver;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\SeriesRepository;
use App\Module\Review\Service\DocumentApiProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[AsTargetedValueResolver(self::NAME)]
final readonly class ProjectSeriesValueResolver implements ValueResolverInterface
{
    public const string NAME = 'project_series';

    public function __construct(
        private ReachableProjectValueResolver $projects,
        private SeriesRepository $series,
    ) {
    }

    /**
     * @return list<Series>
     *
     * @throws ApiProblemException `project_not_found` for a project the credential does not reach, `series_not_found` for a series outside it
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $project = $this->projects->projectOf($request, 'project');
        $name = $request->attributes->get($argument->getName());
        $series = \is_string($name) ? $this->series->findOneByProjectAndName($project, $name) : null;

        return [$series ?? throw DocumentApiProblems::seriesNotFound()];
    }
}
