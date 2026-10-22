<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Service\BetaCompGranter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaCompGranterTest extends KernelTestCase
{
    public function test_a_grant_that_closes_the_entity_manager_still_clears_the_welcome_email(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Closed Tester', email: 'closed-tester@example.com', password: 'x');
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $em->persist($user);
        $em->persist($invite);
        $em->flush();

        // A failed flush closes the EntityManager, and the grant then throws.
        $em->close();
        $granted = self::getContainer()->get(BetaCompGranter::class)($invite);

        self::assertFalse($granted);
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT welcome_email_due_at FROM beta_invites WHERE id = :id',
            ['id' => $invite->id?->toRfc4122()],
        ));
    }
}
