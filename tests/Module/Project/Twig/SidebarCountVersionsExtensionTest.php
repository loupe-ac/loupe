<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Twig;

use App\Mercure\CountChangeGate;
use App\Module\Bridge\Service\SidebarCountsSignal;
use App\Module\Project\Entity\Project;
use App\Module\Project\Twig\SidebarCountVersionsExtension;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class SidebarCountVersionsExtensionTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->setInboxFlag(true);
    }

    public function test_the_function_is_registered_and_answers_a_version_for_each_pill_group(): void
    {
        $project = $this->signedInProject();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $function = $twig->getFunction('sidebar_count_versions');
        self::assertNotNull($function);
        $callable = $function->getCallable();
        self::assertIsCallable($callable);
        $versions = $callable($project);

        self::assertIsArray($versions);
        self::assertSame(['agents', 'inbox'], array_keys($versions));
        self::assertNotSame('', $versions['agents']);
        self::assertNotSame('', $versions['inbox']);
    }

    public function test_two_calls_on_a_quiet_project_answer_the_same_versions(): void
    {
        $project = $this->signedInProject();

        self::assertSame($this->extension()->versions($project), $this->extension()->versions($project));
    }

    public function test_a_signal_moves_the_agents_version(): void
    {
        $project = $this->signedInProject();
        $before = $this->extension()->versions($project);

        $gate = self::getContainer()->get(CountChangeGate::class);
        self::assertInstanceOf(CountChangeGate::class, $gate);
        $gate->changed(SidebarCountsSignal::versionKey($project), static fn (): string => 'moved');
        $after = $this->extension()->versions($project);

        self::assertNotSame($before['agents'], $after['agents']);
        self::assertSame($before['inbox'], $after['inbox']);
    }

    public function test_the_inbox_version_is_empty_while_the_inbox_is_off(): void
    {
        $project = $this->signedInProject();
        $this->setInboxFlag(false);

        $versions = $this->extension()->versions($project);

        self::assertSame('', $versions['inbox']);
        self::assertNotSame('', $versions['agents']);
    }

    public function test_with_nobody_signed_in_both_versions_are_empty(): void
    {
        $project = $this->signedInProject();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(null);

        self::assertSame(['agents' => '', 'inbox' => ''], $this->extension()->versions($project));
    }

    private function signedInProject(): Project
    {
        $owner = $this->signedUpUser($this->em, 'sidebar-versions');
        $project = $this->inboxProject($this->em, $owner);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($owner, 'main', $owner->getRoles()));

        return $project;
    }

    private function extension(): SidebarCountVersionsExtension
    {
        $extension = self::getContainer()->get(SidebarCountVersionsExtension::class);
        self::assertInstanceOf(SidebarCountVersionsExtension::class, $extension);

        return $extension;
    }
}
