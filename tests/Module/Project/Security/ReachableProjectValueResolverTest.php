<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Security;

use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Command\PageProjectsCommand;
use App\Module\Project\Command\PageProjectsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProjectValueResolver;
use App\Security\AuthenticatedCredential;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/** What a credential reaches among the projects of its owner, for a credential no HTTP test can mint yet. */
final class ReachableProjectValueResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $owner;
    private Project $bound;
    private Project $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = new User(fullName: 'Riley Chen', email: 'reachable-'.bin2hex(random_bytes(4)).'@example.com', password: 'x');
        AcceptedTerms::stamp($this->owner, self::getContainer());
        $this->em->persist($this->owner);
        $this->bound = new Project($this->owner, 'Bound', null, new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->other = new Project($this->owner, 'Other', null, new \DateTimeImmutable('2026-01-02 10:00:00'));
        $this->em->persist($this->bound);
        $this->em->persist($this->other);
        $this->em->flush();
    }

    public function test_a_credential_bound_to_one_project_resolves_that_project_by_id_and_by_slug(): void
    {
        $this->actAs(projectId: $this->bound->id);

        self::assertSame($this->bound, $this->resolve((string) $this->bound->id));
        self::assertSame($this->bound, $this->resolve('bound'));
    }

    public function test_a_credential_bound_to_one_project_cannot_resolve_another_project_of_its_owner(): void
    {
        $this->actAs(projectId: $this->bound->id);

        $this->expectException(ApiProblemException::class);
        $this->expectExceptionCode(404);

        $this->resolve((string) $this->other->id);
    }

    public function test_a_credential_that_names_no_project_resolves_every_project_of_its_owner(): void
    {
        $this->actAs(projectId: null);

        self::assertSame($this->bound, $this->resolve('bound'));
        self::assertSame($this->other, $this->resolve((string) $this->other->id));
    }

    public function test_a_handle_that_names_no_project_answers_project_not_found(): void
    {
        $this->actAs(projectId: null);

        try {
            $this->resolve('no-such-project');
            self::fail('The handle resolved.');
        } catch (ApiProblemException $exception) {
            self::assertSame('project_not_found', $exception->problem->code);
        }
    }

    public function test_the_voter_keeps_a_bound_credential_inside_its_project(): void
    {
        $this->actAs(projectId: $this->bound->id);
        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);

        self::assertTrue($checker->isGranted(ProjectVoter::VIEW, $this->bound));
        self::assertTrue($checker->isGranted(ProjectVoter::MANAGE, $this->bound));
        self::assertFalse($checker->isGranted(ProjectVoter::VIEW, $this->other));
        self::assertFalse($checker->isGranted(ProjectVoter::MANAGE, $this->other));
    }

    public function test_a_list_for_a_bound_credential_holds_its_project_alone(): void
    {
        $handler = self::getContainer()->get(PageProjectsHandler::class);
        self::assertInstanceOf(PageProjectsHandler::class, $handler);

        $bound = $handler(new PageProjectsCommand($this->owner, $this->bound->id, new PageRequest(50, null), CreatedSort::oldest()));
        $all = $handler(new PageProjectsCommand($this->owner, null, new PageRequest(50, null), CreatedSort::oldest()));

        self::assertSame([$this->bound], $bound->items);
        self::assertSame([$this->bound, $this->other], $all->items);
    }

    private function resolve(string $handle): Project
    {
        $resolver = self::getContainer()->get(ReachableProjectValueResolver::class);
        self::assertInstanceOf(ReachableProjectValueResolver::class, $resolver);

        $resolved = $resolver->resolve(new Request(attributes: ['project' => $handle]), new ArgumentMetadata('project', Project::class, false, false, null));
        self::assertCount(1, $resolved);

        return $resolved[0];
    }

    private function actAs(?Uuid $projectId): void
    {
        $roles = [ApiScope::Public->role(), ApiScope::Mcp->role()];
        $token = new PostAuthenticationToken($this->owner, 'api', [...$this->owner->getRoles(), ...$roles]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('oauth:test:'.bin2hex(random_bytes(4)), $roles, $projectId));
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken($token);
    }
}
