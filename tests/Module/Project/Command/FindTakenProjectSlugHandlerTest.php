<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Command\FindTakenProjectSlugCommand;
use App\Module\Project\Command\FindTakenProjectSlugHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FindTakenProjectSlugHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FindTakenProjectSlugHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->handler = new FindTakenProjectSlugHandler($projects);
    }

    public function test_it_finds_the_slug_by_name(): void
    {
        $owner = $this->owner('taken-name@example.com');
        $this->em->persist(new Project($owner, 'Blog Site'));
        $this->em->flush();

        self::assertSame('blog-site', ($this->handler)(new FindTakenProjectSlugCommand($owner, 'Blog Site')));
    }

    public function test_it_finds_the_slug_when_only_the_slug_clashes(): void
    {
        $owner = $this->owner('taken-slug@example.com');
        $this->em->persist(new Project($owner, 'Blog'));
        $this->em->flush();

        self::assertSame('blog', ($this->handler)(new FindTakenProjectSlugCommand($owner, 'blog')));
    }

    public function test_it_finds_nothing_for_a_free_name_or_another_owner(): void
    {
        $owner = $this->owner('taken-none@example.com');
        $other = $this->owner('taken-other@example.com');
        $this->em->persist(new Project($other, 'Blog'));
        $this->em->flush();

        self::assertNull(($this->handler)(new FindTakenProjectSlugCommand($owner, 'Blog')));
    }

    /** @param non-empty-string $email */
    private function owner(string $email): User
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);

        return $owner;
    }
}
