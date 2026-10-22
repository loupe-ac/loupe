<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SaveBoardAutomationSettingsHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $this->project = $this->makeProject('save-automation');
    }

    public function test_it_stores_and_audits_the_master_switch(): void
    {
        $this->save(enabled: false);

        self::assertFalse($this->stored()->enabled);
        self::assertFalse($this->audit->record('board.automation_settings_saved')->context['enabled']);

        $this->save(enabled: true);

        self::assertTrue($this->stored()->enabled);
    }

    public function test_it_stores_and_audits_the_stuck_delay(): void
    {
        $this->save(enabled: true, stuckDelayMinutes: 40);

        self::assertSame(40, $this->stored()->stuckDelayMinutes);
        self::assertSame(40, $this->audit->record('board.automation_settings_saved')->context['stuckDelayMinutes']);
    }

    public function test_a_changed_stuck_delay_makes_the_markers_of_the_open_cards_due(): void
    {
        $open = $this->storedCard('next', 1);
        $finished = $this->storedCard('done', 2);

        $this->save(enabled: true, stuckDelayMinutes: 40);

        self::assertNotNull($this->dueAt($open));
        self::assertNull($this->dueAt($finished));
    }

    public function test_an_unchanged_stuck_delay_leaves_the_due_times_alone(): void
    {
        $open = $this->storedCard('next', 1);

        $this->save(enabled: false);

        self::assertNull($this->dueAt($open));
    }

    #[DataProvider('invalidStuckDelays')]
    public function test_it_refuses_a_stuck_delay_out_of_range(int $minutes): void
    {
        try {
            $this->save(enabled: true, stuckDelayMinutes: $minutes);
            self::fail('A delay out of range must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['stuckDelayMinutes' => SaveBoardAutomationSettingsHandler::STUCK_DELAY_INVALID], $e->errors);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function invalidStuckDelays(): iterable
    {
        yield 'zero' => [0];
        yield 'over a day' => [1441];
    }

    private function save(bool $enabled, int $stuckDelayMinutes = BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES): void
    {
        $handler = self::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $handler);
        $handler(new SaveBoardAutomationSettingsCommand(project: $this->project, enabled: $enabled, stuckDelayMinutes: $stuckDelayMinutes));
    }

    private function storedCard(string $columnSlug, int $number): Card
    {
        $card = new Card(project: $this->project, column: $this->column($this->project, $columnSlug), title: 'Card '.$number, body: '', number: $number);
        $card->markers = [];
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function dueAt(Card $card): ?string
    {
        $due = $this->em->getConnection()->fetchOne('SELECT workflow_markers_due_at FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);

        return false === $due ? null : $due;
    }

    private function stored(): BoardAutomationSettings
    {
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $settings = $repository->findOneByProject($this->project);
        self::assertNotNull($settings);
        $this->em->refresh($settings);

        return $settings;
    }
}
