<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\UpdateProfileCommand;
use App\Module\Account\Command\UpdateProfileHandler;
use App\Module\Account\Entity\User;
use App\Tests\Support\CapturedAccountSignals;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpdateProfileHandlerSignalTest extends KernelTestCase
{
    private CapturedAccountSignals $signals;
    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->signals = CapturedAccountSignals::install(self::getContainer());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->user = new User(fullName: 'Signal Person', email: 'profile-signal@example.com', password: 'x');
        $em->persist($this->user);
        $em->flush();
    }

    public function test_a_new_name_signals_the_profile_area_of_the_user(): void
    {
        self::getContainer()->get(UpdateProfileHandler::class)(new UpdateProfileCommand($this->user, 'Renamed Person'));
        $this->signals->endRequest();

        self::assertSame(['profile'], $this->signals->areasOf($this->user->id ?? throw new \LogicException('no id')));
    }

    public function test_the_same_name_changes_nothing_and_signals_nothing(): void
    {
        self::getContainer()->get(UpdateProfileHandler::class)(new UpdateProfileCommand($this->user, '  Signal Person '));
        $this->signals->endRequest();

        self::assertSame([], $this->signals->areasOf($this->user->id ?? throw new \LogicException('no id')));
    }
}
