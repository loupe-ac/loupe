<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\RepositoryChange;
use App\Module\Forge\Service\RepositoryChecks;
use App\Module\Forge\Service\RepositoryHoldSummaries;
use App\Module\Forge\Service\RepositorySettingsReaders;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks one repository against the suggestions of its project template. The
 * read runs outside any lock, and the summary writer drops the result when a
 * newer read was accepted first.
 */
final readonly class RunRepositoryCheckHandler
{
    public const string FAILURE_UNSUPPORTED_FORGE = 'unsupported_forge';

    public function __construct(
        private RepositorySuggestions $suggestions,
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private RepositoryChecks $checks,
        private RepositoryHoldSummaries $summaries,
        private RepositorySettingsReaders $readers,
        private RepositorySuggestionCatalog $catalog,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunRepositoryCheckCommand $command): void
    {
        $repository = $command->repository;
        $repositoryId = $repository->id ?? throw new \LogicException('A persisted repository has an id.');
        $projectId = $repository->project->id ?? throw new \LogicException('A persisted project has an id.');

        $suggestions = $this->suggestions->forProject($projectId);
        if ([] === $suggestions) {
            $this->clearStaleItems($command);

            return;
        }

        if (CheckTrigger::CheckAgain !== $command->trigger && $this->checks->isFresh($this->forgeRepositoryChecks->findOneOfRepository($repository), $this->clock->now())) {
            if (CheckTrigger::PageOpen === $command->trigger) {
                $this->summaries->signal($projectId, $repositoryId);
            }
            $this->logger->info('forge.repository_check_reused', ['repositoryId' => (string) $repositoryId, 'trigger' => $command->trigger->value]);

            return;
        }

        $ticket = $this->forgeRepositoryChecks->takeReadTicket($repository);

        $settings = null;
        $failure = null;
        try {
            $reader = $this->readers->for($repository->forge) ?? throw new RepositorySettingsUnreadable(self::FAILURE_UNSUPPORTED_FORGE);
            $settings = $reader->read($repository);
        } catch (RepositorySettingsUnreadable $e) {
            $failure = $e->reason;
        }

        $accepted = $this->summaries->change($repository->project, function (RepositoryChange $change) use ($command, $repositoryId, $ticket, $settings, $failure, $suggestions): bool {
            $check = $this->forgeRepositoryChecks->findOneOfRepository($command->repository);
            if (null === $check) {
                return false;
            }
            $this->em->refresh($check);
            if ($ticket <= $check->acceptedReadTicket) {
                return false;
            }

            $check->acceptedReadTicket = $ticket;
            $now = $this->clock->now();
            if (null === $settings) {
                $check->attemptedAt = $now;
                $check->failure = $failure;
            } else {
                $check->checkedAt = $now;
                $check->failure = null;
                $check->items = $this->items($suggestions, $settings);
                $check->settings = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            }
            $change->signal($repositoryId);

            return true;
        });

        $this->logger->info($accepted ? 'forge.repository_checked' : 'forge.repository_check_dropped', [
            'repositoryId' => (string) $repositoryId,
            'trigger' => $command->trigger->value,
            'ticket' => $ticket,
            'failure' => $failure,
        ]);
    }

    /** A template copy that drops its last suggestion leaves items that must hold no longer. */
    private function clearStaleItems(RunRepositoryCheckCommand $command): void
    {
        $check = $this->forgeRepositoryChecks->findOneOfRepository($command->repository);
        if (null === $check || [] === $check->items) {
            return;
        }

        $ticket = $this->forgeRepositoryChecks->takeReadTicket($command->repository);
        $repositoryId = $command->repository->id ?? throw new \LogicException('A persisted repository has an id.');
        $cleared = $this->summaries->change($command->repository->project, function (RepositoryChange $change) use ($check, $repositoryId, $ticket): bool {
            $this->em->refresh($check);
            if ($ticket <= $check->acceptedReadTicket) {
                return false;
            }

            $check->acceptedReadTicket = $ticket;
            $check->items = [];
            $change->signal($repositoryId);

            return true;
        });

        $this->logger->info($cleared ? 'forge.repository_check_cleared' : 'forge.repository_check_dropped', [
            'repositoryId' => (string) $repositoryId,
            'trigger' => $command->trigger->value,
            'ticket' => $ticket,
        ]);
    }

    /**
     * @param list<RepositorySuggestion> $suggestions
     *
     * @return list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}>
     */
    private function items(array $suggestions, RepositorySettings $settings): array
    {
        $items = [];
        foreach ($suggestions as $suggestion) {
            $evaluation = $this->catalog->evaluate($suggestion->key, $settings);
            $items[] = [
                'key' => $suggestion->key->value,
                'mark' => $suggestion->mark->value,
                'state' => $evaluation->fits() ? ForgeRepositoryCheck::STATE_FITS : ForgeRepositoryCheck::STATE_GAP,
                'gaps' => $evaluation->gaps,
                'parameters' => [],
            ];
        }
        if ($settings->classicProtection) {
            $items[] = [
                'key' => ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION,
                'mark' => SuggestionMark::Warning->value,
                'state' => ForgeRepositoryCheck::STATE_GAP,
                'gaps' => [RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION],
                'parameters' => [],
            ];
        }

        return $items;
    }
}
