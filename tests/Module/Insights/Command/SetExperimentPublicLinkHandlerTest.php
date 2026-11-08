<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Insights\Command\SetExperimentPublicLinkCommand;
use App\Module\Insights\Command\SetExperimentPublicLinkHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Messenger\DrawPublicPreview;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SetExperimentPublicLinkHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_first_share_creates_the_link_with_a_code_and_a_note_and_turns_it_on(): void
    {
        $project = $this->projectWithExperiment('exp-first', 'trial');

        $unarchived = $this->share($project, 'trial', true, "  The model test.\n");

        $link = $this->linkOf($project, 'trial');
        self::assertNotNull($link);
        self::assertTrue($link->enabled);
        self::assertSame('The model test.', $link->note);
        self::assertMatchesRegularExpression('/^'.PublicLinkCode::PATTERN.'$/', $link->code);
        self::assertFalse($unarchived);
    }

    public function test_off_and_on_change_the_switch_and_keep_the_code_and_the_note(): void
    {
        $project = $this->projectWithExperiment('exp-switch', 'trial');
        $this->share($project, 'trial', true, 'Kept.');
        $code = $this->linkOf($project, 'trial')?->code;

        $this->share($project, 'trial', false, 'Kept.');
        self::assertFalse($this->linkOf($project, 'trial')?->enabled);
        self::assertSame($code, $this->linkOf($project, 'trial')->code);

        $this->share($project, 'trial', true, 'Kept.');
        self::assertTrue($this->linkOf($project, 'trial')?->enabled);
        self::assertSame($code, $this->linkOf($project, 'trial')->code);
        self::assertSame('Kept.', $this->linkOf($project, 'trial')?->note);
    }

    public function test_a_blank_note_clears_the_note(): void
    {
        $project = $this->projectWithExperiment('exp-blank', 'trial');
        $this->share($project, 'trial', true, 'Gone soon.');

        $this->share($project, 'trial', true, " \n ");

        self::assertNull($this->linkOf($project, 'trial')?->note);
    }

    public function test_switching_off_an_experiment_that_was_never_shared_creates_no_link(): void
    {
        $project = $this->projectWithExperiment('exp-never', 'trial');

        $this->share($project, 'trial', false, null);

        self::assertNull($this->linkOf($project, 'trial'));
    }

    public function test_a_note_saved_before_the_first_share_creates_a_switched_off_link(): void
    {
        $project = $this->projectWithExperiment('exp-note-first', 'trial');

        $this->share($project, 'trial', false, 'Written first.');

        $link = $this->linkOf($project, 'trial');
        self::assertNotNull($link);
        self::assertFalse($link->enabled);
        self::assertSame('Written first.', $link->note);
    }

    public function test_a_blocked_share_fails_with_the_reason_and_changes_nothing(): void
    {
        $project = $this->projectWithExperiment('exp-blocked', 'trial');
        $this->switchFlagOff();

        try {
            $this->share($project, 'trial', true, 'No.');
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['enabled' => 'insights.public_link.block.flag_off'], $e->errors);
        }

        self::assertTrue($this->em()->isOpen());
        self::assertNull($this->linkOf($project, 'trial'));
    }

    public function test_an_unknown_experiment_cannot_be_shared(): void
    {
        $project = $this->scenarioProject('exp-unknown');

        try {
            $this->share($project, 'ghost', true, null);
            self::fail('Expected a not found.');
        } catch (NotFoundHttpException) {
        }

        self::assertTrue($this->em()->isOpen());
        self::assertNull($this->linkOf($project, 'ghost'));
    }

    public function test_sharing_an_archived_experiment_unarchives_it_in_the_same_call(): void
    {
        $project = $this->projectWithExperiment('exp-archived', 'trial');
        $this->handler(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));
        self::assertSame(['trial'], $this->archivedNames($project));

        $unarchived = $this->share($project, 'trial', true, null);

        self::assertTrue($unarchived);
        self::assertSame([], $this->archivedNames($project));
        self::assertTrue($this->linkOf($project, 'trial')?->enabled);
    }

    public function test_a_blocked_share_leaves_an_archived_experiment_archived(): void
    {
        $project = $this->projectWithExperiment('exp-archived-blocked', 'trial');
        $this->handler(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));
        $this->switchFlagOff();

        try {
            $this->share($project, 'trial', true, null);
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }

        self::assertSame(['trial'], $this->archivedNames($project));
    }

    public function test_switching_off_leaves_an_archived_experiment_archived(): void
    {
        $project = $this->projectWithExperiment('exp-off-archived', 'trial');
        $this->share($project, 'trial', true, null);
        $this->handler(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));

        $this->share($project, 'trial', false, null);

        self::assertSame(['trial'], $this->archivedNames($project));
    }

    public function test_turning_an_experiment_link_on_asks_for_its_preview_image(): void
    {
        $project = $this->projectWithExperiment('share-preview', 'trial');
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->share($project, 'trial', true, null);

        $drawn = array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof DrawPublicPreview,
        );
        self::assertCount(1, $drawn);
    }

    private function projectWithExperiment(string $name, string $experiment): Project
    {
        $project = $this->scenarioProject($name);
        $this->seedExperiment($project, $experiment);

        return $project;
    }

    private function share(Project $project, string $experiment, bool $enabled, ?string $note): bool
    {
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();

        return $this->handler(SetExperimentPublicLinkHandler::class)(new SetExperimentPublicLinkCommand($project, $experiment, $enabled, $note));
    }

    private function switchFlagOff(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
    }

    /** @return list<string> */
    private function archivedNames(Project $project): array
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();
        $view = $this->handler(ListExperimentsHandler::class)(new ListExperimentsCommand($project, true));

        return array_values(array_map(static fn ($summary): string => $summary->name, array_filter($view->experiments, static fn ($summary): bool => $summary->archived)));
    }

    private function linkOf(Project $project, string $experiment): ?PublicLink
    {
        $this->em()->clear();
        $stored = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();

        return $this->handler(PublicLinkRepository::class)->findForExperiment($stored, $experiment);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
