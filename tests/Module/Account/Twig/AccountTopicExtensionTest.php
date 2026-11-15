<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Twig;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Account\Twig\AccountTopicExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountTopicExtensionTest extends KernelTestCase
{
    public function test_it_gives_the_account_topic_of_the_user(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Riley', email: 'account-topic-twig@example.com', password: 'x');
        $em->persist($user);
        $em->flush();
        $topics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);

        $extension = new AccountTopicExtension($topics);

        self::assertSame($topics->forAccount($user->id ?? throw new \LogicException('No id.')), $extension->accountTopic($user));
        self::assertSame(['account_topic'], array_map(static fn ($function): string => $function->getName(), $extension->getFunctions()));
    }
}
