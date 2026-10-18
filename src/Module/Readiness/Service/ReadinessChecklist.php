<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Project\Workshop\WorkshopReadiness;
use App\Module\Project\Workshop\WorkshopReadinessProviderInterface;
use App\Module\Project\Workshop\WorkshopReadinessRow;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\EventListener\FailDiscoveryOnRequestWithdrawn;
use App\Module\Readiness\EventListener\FailDiscoveryOnRunEnded;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\WorkflowAutomation;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(WorkshopReadinessProviderInterface::class)]
final readonly class ReadinessChecklist implements WorkshopReadinessProviderInterface
{
    public const string START_TOKEN = 'readiness-discovery-start';

    /** The work the first run of a project asks a bridge for, with the prompt that the app ships. */
    private const string DISCOVERY_KIND = 'discovery';

    public function __construct(
        private WorkshopConnectionsProviderInterface $connections,
        private WorkflowBindingRepository $workflowBindings,
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubAppConfiguration $gitHubApp,
        private UrlGeneratorInterface $urls,
        private DiscoveryRunRepository $discoveryRuns,
        private TranslatorInterface $translator,
        private WorkflowAutomation $automation,
        private CardEvaluations $evaluations,
        private WorkTakers $workTakers,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): ?WorkshopReadiness
    {
        return null === $project->readinessGuideHiddenAt ? $this->rows($project) : null;
    }

    /** Every check, whether the guide shows or not. */
    public function rows(Project $project): WorkshopReadiness
    {
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $connect = $this->urls->generate('app_project_connect', ['id' => (string) $projectId]);

        $agent = null !== $project->agentFirstSeenAt;
        $binding = $this->workflowBindings->findOneByProjectId($projectId);
        $running = $this->runningConnections($project);
        $takers = $this->workTakers->capableFor($project, self::DISCOVERY_KIND, true);
        $github = $this->gitHubReady($project);
        $installUrl = $github || !$this->gitHubApp->isConfigured() ? null : $this->urls->generate('app_github_app_install', ['id' => (string) $projectId]);
        $discovery = $this->discoveryRuns->latestForProject($project);

        return new WorkshopReadiness([
            new WorkshopReadinessRow(
                'agent',
                'readiness.row.agent.label',
                $agent,
                $agent ? 'readiness.row.agent.done' : 'readiness.row.agent.open',
                actionLabel: $agent ? null : 'readiness.row.agent.action',
                actionUrl: $agent ? null : $connect,
            ),
            $this->bridgeRow($takers, $connect, $this->urls->generate('app_project_agents', ['id' => (string) $projectId])),
            new WorkshopReadinessRow(
                'github',
                'readiness.row.github.label',
                $github,
                $github ? 'readiness.row.github.done' : 'readiness.row.github.open',
                actionLabel: null === $installUrl ? null : 'readiness.row.github.action',
                actionUrl: $installUrl,
            ),
            $this->agentAccountRow($project, $running),
            $this->repositoryRow($project, $discovery, $this->refusal($project, $takers, $binding)),
        ], $discovery?->card->number);
    }

    /** The same reading as the engine takes for the request, so the row and the failure agree. */
    private function bridgeRow(WorkTakerReading $takers, string $connect, string $agents): WorkshopReadinessRow
    {
        [$done, $status, $actionLabel, $actionUrl] = match ($takers->state) {
            WorkTakerState::None => [false, 'readiness.row.bridge.open', 'readiness.row.bridge.action', $connect],
            WorkTakerState::NotCapable => [false, $takers->appPromptsMissing ? 'readiness.row.bridge.not_capable_app_prompts' : 'readiness.row.bridge.not_capable', 'readiness.row.bridge.action', $connect],
            WorkTakerState::Paused => [false, 'readiness.row.bridge.paused', 'readiness.row.bridge.resume', $agents],
            WorkTakerState::Available => [true, 'readiness.row.bridge.done', null, null],
        };

        return new WorkshopReadinessRow(
            'bridge',
            'readiness.row.bridge.label',
            $done,
            $status,
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
        );
    }

    /** The error key that refuses a discovery start now, or null when the engine can open its request. */
    public function startRefusal(Project $project): ?string
    {
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');

        return $this->refusal($project, $this->workTakers->capableFor($project, self::DISCOVERY_KIND, true), $this->workflowBindings->findOneByProjectId($projectId));
    }

    /** The same gates as the engine, which skips a card of a project with no workflow or with its automation off. */
    private function refusal(Project $project, WorkTakerReading $takers, ?WorkflowBinding $binding): ?string
    {
        return match (true) {
            WorkTakerState::None === $takers->state => StartDiscoveryHandler::NO_LIVE_BRIDGE,
            WorkTakerState::NotCapable === $takers->state => $takers->appPromptsMissing ? StartDiscoveryHandler::NO_CAPABLE_BRIDGE_APP_PROMPTS : StartDiscoveryHandler::NO_CAPABLE_BRIDGE,
            WorkTakerState::Paused === $takers->state => StartDiscoveryHandler::BRIDGE_PAUSED,
            null === $binding => StartDiscoveryHandler::NO_WORKFLOW,
            !$this->evaluations->isOn() || !$this->automation->runsFor($project) => StartDiscoveryHandler::AUTOMATION_OFF,
            default => null,
        };
    }

    private function repositoryRow(Project $project, ?DiscoveryRun $run, ?string $refusal): WorkshopReadinessRow
    {
        $canStart = null === $refusal;
        $start = $this->urls->generate('app_readiness_discovery_start', ['id' => (string) $project->id]);
        $row = static fn (string $status, bool $done = false, ?string $actionLabel = null, ?string $actionUrl = null, array $parameters = [], ?string $csrf = null): WorkshopReadinessRow => new WorkshopReadinessRow(
            'repository',
            'readiness.row.repository.label',
            $done,
            $status,
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
            statusParameters: $parameters,
            actionCsrfTokenId: $csrf,
            discoveryState: $run?->state->value ?? 'none',
        );

        return match ($run?->state) {
            null => match ($refusal) {
                null => $row('readiness.row.repository.open', actionLabel: 'readiness.row.repository.action', actionUrl: $start, csrf: self::START_TOKEN),
                StartDiscoveryHandler::NO_WORKFLOW => $row('readiness.row.repository.no_workflow'),
                StartDiscoveryHandler::AUTOMATION_OFF => $row('readiness.row.repository.automation_off'),
                StartDiscoveryHandler::NO_LIVE_BRIDGE => $row('readiness.row.repository.no_bridge'),
                StartDiscoveryHandler::NO_CAPABLE_BRIDGE, StartDiscoveryHandler::NO_CAPABLE_BRIDGE_APP_PROMPTS => $row('readiness.row.repository.no_capable_bridge'),
                StartDiscoveryHandler::BRIDGE_PAUSED => $row('readiness.row.repository.bridge_paused'),
                default => throw new \LogicException('Unknown discovery refusal '.$refusal),
            },
            DiscoveryRunState::Requested => $row(
                'readiness.row.repository.running',
                actionLabel: 'readiness.row.repository.card',
                actionUrl: $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $run->card->id]),
                parameters: ['%number%' => (string) $run->card->number],
            ),
            DiscoveryRunState::Failed => $row(
                'readiness.row.repository.failed',
                actionLabel: $canStart ? 'readiness.row.repository.retry' : null,
                actionUrl: $canStart ? $start : null,
                parameters: ['%reason%' => $this->failureReason($run)],
                csrf: $canStart ? self::START_TOKEN : null,
            ),
            DiscoveryRunState::Revising => $row(
                'readiness.row.repository.revising',
                actionLabel: null === $run->reportDocument ? null : 'readiness.row.repository.open_report',
                actionUrl: null === $run->reportDocument ? null : $this->urls->generate('app_document_review', ['projectId' => (string) $project->id, 'documentId' => (string) $run->reportDocument->id]),
                parameters: ['%number%' => (string) $run->card->number],
            ),
            DiscoveryRunState::Reported => $row(
                'readiness.row.repository.reported',
                actionLabel: null === $run->reportDocument ? null : 'readiness.row.repository.open_report',
                actionUrl: null === $run->reportDocument ? null : $this->urls->generate('app_document_review', ['projectId' => (string) $project->id, 'documentId' => (string) $run->reportDocument->id]),
            ),
            DiscoveryRunState::Done => $row('readiness.row.repository.done', done: true),
        };
    }

    private function failureReason(DiscoveryRun $run): string
    {
        $reason = $run->failureReason ?? $run->state->value;

        return match ($reason) {
            FailDiscoveryOnRequestWithdrawn::NO_TAKER => $this->translator->trans('readiness.discovery.reason.no_taker'),
            FailDiscoveryOnRequestWithdrawn::CARD_MOVED => $this->translator->trans('readiness.discovery.reason.card_moved'),
            WorkRequest::REASON_NO_CAPABLE_TAKER => $this->translator->trans('readiness.discovery.reason.no_capable_taker'),
            WorkRequest::REASON_NO_CAPABLE_TAKER_APP_PROMPTS => $this->translator->trans('readiness.discovery.reason.no_capable_taker_app_prompts'),
            FailDiscoveryOnRunEnded::WORKER_ENDED => $this->translator->trans('readiness.discovery.reason.worker_ended', ['%number%' => (string) $run->card->number]),
            default => $reason,
        };
    }

    /** The agent account check alone, which its own page shows while the guide is hidden too. */
    public function agentAccount(Project $project): WorkshopReadinessRow
    {
        return $this->agentAccountRow($project, $this->runningConnections($project));
    }

    /** @param list<WorkshopConnection> $running */
    private function agentAccountRow(Project $project, array $running): WorkshopReadinessRow
    {
        $login = $project->agentGitHubLogin;
        if (null === $login) {
            $status = 'readiness.row.agent_account.no_login';
            $done = false;
        } else {
            $done = array_any($running, static fn (WorkshopConnection $connection): bool => null !== $connection->pushLogin && 0 === strcasecmp($connection->pushLogin, $login));
            $status = $done ? 'readiness.row.agent_account.done' : 'readiness.row.agent_account.no_push';
        }

        return new WorkshopReadinessRow(
            'agent_account',
            'readiness.row.agent_account.label',
            $done,
            $status,
            actionLabel: $done ? null : 'readiness.row.agent_account.action',
            actionUrl: $done ? null : $this->urls->generate('app_project_readiness_agent_account', ['id' => (string) $project->id]),
            statusParameters: null === $login ? [] : ['%login%' => $login],
        );
    }

    /** @return list<WorkshopConnection> */
    private function runningConnections(Project $project): array
    {
        return array_values(array_filter($this->connections->forProject($project), static fn (WorkshopConnection $connection): bool => !$connection->quiet));
    }

    /** A live installation must hold the repository, as a pull request write needs. */
    private function gitHubReady(Project $project): bool
    {
        $live = [];
        foreach ($this->gitHubInstallations->findByProject($project) as $installation) {
            if (null === $installation->removedAt && null === $installation->suspendedAt) {
                $live[(string) $installation->installationId] = true;
            }
        }

        return array_any($this->forgeRepositories->findByProject($project), fn ($repository) => ForgeRepositorySource::Installation === $repository->source && isset($live[$repository->sourceRef ?? '']));
    }
}
