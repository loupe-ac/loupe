<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Workflow;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscoveryCardMoveLockTest extends KernelTestCase
{
    use DiscoveryScenario;

    #[DataProvider('states')]
    public function test_a_person_cannot_move_a_discovery_card_while_a_worker_writes_or_revises_the_report(DiscoveryRunState $state, bool $allowed): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-lock-'.$state->value);
        $this->bindLifecycle($project);
        $card = $this->discoveryCard($project);
        $this->discoveryRun($card, $state);
        $guard = self::getContainer()->get(CardMoveGuard::class);
        self::assertInstanceOf(CardMoveGuard::class, $guard);

        self::assertSame($allowed, $guard->allows($card->snapshot(), $this->column($project, 'next')->ref(), Actor::Human, null));
        self::assertTrue($guard->allows($card->snapshot(), $this->column($project, 'next')->ref(), Actor::System, null));
    }

    /** @return iterable<string, array{DiscoveryRunState, bool}> */
    public static function states(): iterable
    {
        yield 'requested' => [DiscoveryRunState::Requested, false];
        yield 'revising' => [DiscoveryRunState::Revising, false];
        yield 'reported' => [DiscoveryRunState::Reported, true];
        yield 'done' => [DiscoveryRunState::Done, true];
        yield 'failed' => [DiscoveryRunState::Failed, true];
    }

    #[DataProvider('unmanaged')]
    public function test_the_lock_holds_while_the_card_is_unmanaged(string $unmanaged): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-lock-'.$unmanaged);
        if ('no-template' !== $unmanaged) {
            $this->bindLifecycle($project);
        }
        $card = $this->discoveryCard($project);
        $this->discoveryRun($card, DiscoveryRunState::Revising);
        if ('automation-off' === $unmanaged) {
            $automation = self::getContainer()->get(BoardAutomation::class);
            self::assertInstanceOf(BoardAutomation::class, $automation);
            $automation->settingsForUpdate($project)->enabled = false;
            $this->em()->flush();
        }
        if ('held' === $unmanaged) {
            $holds = self::getContainer()->get(CardHolds::class);
            self::assertInstanceOf(CardHolds::class, $holds);
            $holds->hold($project, $card->id ?? throw new \LogicException('A stored card has an id.'), null);
        }
        $guard = self::getContainer()->get(CardMoveGuard::class);
        self::assertInstanceOf(CardMoveGuard::class, $guard);

        self::assertFalse($guard->allows($card->snapshot(), $this->column($project, 'next')->ref(), Actor::Human, null));
        self::assertTrue($guard->allows($card->snapshot(), $this->column($project, 'next')->ref(), Actor::System, null));
    }

    /** @return iterable<string, array{string}> */
    public static function unmanaged(): iterable
    {
        yield 'automation off' => ['automation-off'];
        yield 'no template' => ['no-template'];
        yield 'held' => ['held'];
    }
}
