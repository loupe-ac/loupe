<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DiscoveryRuleTest extends KernelTestCase
{
    use DiscoveryScenario;

    #[DataProvider('templates')]
    public function test_a_requested_run_opens_one_discovery_request_that_carries_the_prompt(string $template): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject($template));
        $this->discoveryRun($card);
        $this->bridge($card, [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS]);

        $this->evaluate($card, '2026-10-02 12:00:00');
        $this->evaluate($card, '2026-10-02 12:05:00');

        $requests = $this->requests($card);
        self::assertCount(1, $requests);
        self::assertSame(['discovery', 'discovery', WorkRequestState::Open], [$requests[0]->kind, $requests[0]->ruleId, $requests[0]->state]);
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery.md'), $requests[0]->prompt);
    }

    #[DataProvider('templates')]
    public function test_a_request_with_no_bridge_online_fails_the_run_at_once_without_a_pause(string $template): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject($template));
        $run = $this->discoveryRun($card);

        $this->evaluate($card, '2026-10-02 12:00:00');
        $this->evaluate($card, '2026-10-02 12:00:30');

        $requests = $this->requests($card);
        self::assertCount(1, $requests);
        self::assertSame([WorkRequestState::Expired, WorkRequest::REASON_NO_TAKER], [$requests[0]->state, $requests[0]->reason]);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_TAKER], [$run->state, $run->failureReason]);
        self::assertNull($this->pause($card));
    }

    public function test_a_request_with_an_online_bridge_that_cannot_run_it_fails_the_run_with_that_cause(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'));
        $run = $this->discoveryRun($card);
        $this->bridge($card, [Bridge::CAPABILITY_WORK_REQUESTS]);

        $this->evaluate($card, '2026-10-02 12:00:00');
        $this->evaluate($card, '2026-10-02 12:00:30');

        $requests = $this->requests($card);
        self::assertSame([WorkRequestState::Expired, WorkRequest::REASON_NO_CAPABLE_TAKER_APP_PROMPTS], [$requests[0]->state, $requests[0]->reason]);
        self::assertSame([DiscoveryRunState::Failed, WorkRequest::REASON_NO_CAPABLE_TAKER_APP_PROMPTS], [$run->state, $run->failureReason]);
    }

    #[DataProvider('templates')]
    public function test_a_failed_run_opens_no_request(string $template): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject($template));
        $this->discoveryRun($card, DiscoveryRunState::Failed);

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertSame([], $this->requests($card));
    }

    public function test_a_card_with_no_run_opens_no_request(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'));

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertSame([], $this->requests($card));
    }

    public function test_a_new_run_after_a_failed_one_opens_a_new_request(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'));
        $this->discoveryRun($card, createdAt: '2026-10-02 12:00:00');
        $this->evaluate($card, '2026-10-02 12:00:00');
        $failDiscoveryRun = self::getContainer()->get(FailDiscoveryRunHandler::class);
        self::assertInstanceOf(FailDiscoveryRunHandler::class, $failDiscoveryRun);

        self::assertTrue($failDiscoveryRun(new FailDiscoveryRunCommand($card->id ?? throw new \LogicException('A flushed card has an id.'), 'lost')));
        $this->evaluate($card, '2026-10-02 12:10:00');
        $this->discoveryRun($card, createdAt: '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:20:00');

        $requests = $this->requests($card);
        self::assertSame([WorkRequestState::Cancelled, WorkRequestState::Open], array_map(static fn (WorkRequest $request): WorkRequestState => $request->state, $requests));
        $ruleStates = self::getContainer()->get(WorkflowRuleStateRepository::class);
        self::assertInstanceOf(WorkflowRuleStateRepository::class, $ruleStates);
        self::assertSame(2, $ruleStates->findForCard($card->snapshot()->id)['discovery']->fires);
    }

    public function test_a_requested_run_outside_the_backlog_opens_no_request(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->boundProject('lifecycle'), 'done');
        $this->discoveryRun($card);

        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertNotContains('discovery', array_map(static fn (WorkRequest $request): string => $request->kind, $this->requests($card)));
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    /** @param list<string> $capabilities */
    private function bridge(Card $card, array $capabilities): void
    {
        $bridge = new Bridge(AgentCredential::managed($this->em(), $card->project->owner, $card->project->owner->id), Uuid::v4(), [(string) $card->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;
        $this->em()->persist($bridge);
        $this->em()->flush();
    }

    private function pause(Card $card): ?CardPause
    {
        $pauses = self::getContainer()->get(CardPauseRepository::class);
        self::assertInstanceOf(CardPauseRepository::class, $pauses);

        return $pauses->findActiveForCard($card);
    }

    private function boundProject(string $template): Project
    {
        $project = $this->workflowProject('discovery-rule');
        if ('lifecycle' === $template) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, $template, []));
        }

        return $project;
    }

    private function evaluate(Card $card, string $at): void
    {
        $engine = self::getContainer()->get(Engine::class);
        self::assertInstanceOf(Engine::class, $engine);
        $engine->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    /** @return list<WorkRequest> */
    private function requests(Card $card): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return array_values($repository->findBy(['subjectId' => $card->id], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }
}
