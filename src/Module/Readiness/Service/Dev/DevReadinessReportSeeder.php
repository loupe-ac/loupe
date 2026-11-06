<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes two discovery reports in the dev project: one whose suggested findings name an action for the owner
 * and for an agent, and an empty twin whose findings are all ready, so it lists nothing under What remains.
 */
#[When('dev')]
#[When('test')]
final readonly class DevReadinessReportSeeder implements DevSeederInterface
{
    public const string KEY = 'readiness-reports';

    public const string OWNER_ACTION = 'Install the codex-cli MCP server in the worker account.';

    public const string AGENT_ACTION = 'Add the setup command of the e2e stack to AGENTS.md.';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private DocumentTagApplier $tagApplier,
        private ReadinessReportWriter $writer,
        private MarkdownRenderer $renderer,
    ) {
    }

    public static function reportId(string $slug): Uuid
    {
        return DevSeedId::of('readiness/report/'.$slug);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $ready = new ReportFinding('A worktree per card', ReadinessReportWriter::READY, 'The bridge gives each card its own worktree.');
        $this->report($context, 'actions', 'Discovery report: what remains', [
            $ready,
            new ReportFinding('Each tool that the profile names exists for the worker account', ReadinessReportWriter::SUGGESTED, 'The profile names `codex-cli`, and the account declares no such MCP server.', ReportFinding::OWNER, self::OWNER_ACTION),
            new ReportFinding('Each fact that a worker needs is in the repository', ReadinessReportWriter::SUGGESTED, 'The e2e setup lives only in a personal memory file.', ReportFinding::AGENT, self::AGENT_ACTION),
        ]);
        $this->report($context, 'nothing-remains', 'Discovery report: nothing remains', [$ready]);
    }

    /** @param list<ReportFinding> $findings */
    private function report(SeedContext $context, string $slug, string $title, array $findings): void
    {
        if ($this->documents->find(self::reportId($slug)) instanceof Document) {
            return;
        }
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');

        $markdown = $this->writer->write('lifecycle', 'The seeded report of a discovery run.', $findings, []);
        $document = new Document($context->devUser(), $project, $title, $context->at(900));
        $document->addVersion($markdown, $this->renderer->render($markdown));
        $this->tagApplier->apply($document, [SubmitReadinessReportHandler::TAG]);
        $context->ids->assign($document, self::reportId($slug));
        $this->em->persist($document);
        $this->em->flush();
    }
}
