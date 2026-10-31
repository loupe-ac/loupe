<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Workflow\Contract\EpicBranches;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkRequestContextsTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_child_carries_the_epic_branch_of_its_parent(): void
    {
        [$epic, $child] = $this->epicAndChild();

        $context = $this->workRequestContexts('epic/{number}')->forCard($child, null);

        self::assertSame('epic/'.$epic->number, $context->epicBranch);
    }

    public function test_the_epic_branch_rides_on_a_context_that_names_a_document_and_a_reason(): void
    {
        [$epic, $child] = $this->epicAndChild();

        $context = $this->workRequestContexts('epic/{number}')->forCard($child, null, '01a10beb-ba65-736b-8626-a6e3fa59dfc5', 'checks-failed');

        self::assertSame(['epic/'.$epic->number, 'checks-failed'], [$context->epicBranch, $context->reason]);
    }

    public function test_a_card_with_no_parent_carries_no_epic_branch(): void
    {
        [$epic] = $this->epicAndChild();

        self::assertNull($this->workRequestContexts('epic/{number}')->forCard($epic, null)->epicBranch);
    }

    public function test_a_project_whose_template_has_no_epic_branch_gives_a_child_none(): void
    {
        [, $child] = $this->epicAndChild();

        self::assertNull($this->workRequestContexts(null)->forCard($child, null)->epicBranch);
    }

    /** @return array{Card, Card} */
    private function epicAndChild(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('work-request-contexts');
        $epic = $this->card($project, 'next');
        $child = $this->card($project, 'next');
        $child->parent = $epic;
        $this->em()->flush();

        return [$epic, $child];
    }

    private function workRequestContexts(?string $pattern): WorkRequestContexts
    {
        $branches = new readonly class($pattern) implements EpicBranches {
            public function __construct(
                private ?string $pattern,
            ) {
            }

            #[\Override]
            public function of(Uuid $projectId, int $number): ?string
            {
                return null === $this->pattern ? null : str_replace('{number}', (string) $number, $this->pattern);
            }
        };

        return new WorkRequestContexts($this->service(CardPullRequests::class), $this->service(CardPullRequestRepository::class), $branches);
    }
}
