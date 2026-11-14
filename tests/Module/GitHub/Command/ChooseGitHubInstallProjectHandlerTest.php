<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Command\AddGitHubRepositoriesHandler;
use App\Module\GitHub\Command\ChooseGitHubInstallProjectCommand;
use App\Module\GitHub\Command\ChooseGitHubInstallProjectHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubPickerListing;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

final class ChooseGitHubInstallProjectHandlerTest extends KernelTestCase
{
    public function test_a_project_that_the_voter_refuses_binds_nothing_and_creates_nothing(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Riley', email: 'choose-voter@example.com', password: 'x');
        AcceptedTerms::stamp($owner, $container);
        $project = new Project($owner, 'choose-voter');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $installSession = new GitHubInstallSession($stack, new Encryptor(new EncryptionKeyProvider([base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))])), new MockClock('2026-11-10 12:00:00'), new NullLogger());
        $installSession->signIn($installSession->beginSignIn(null, null, installationHint: 4242), 'ghu_token');

        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with(GitHubConnectionVoter::MANAGE, $project)->willReturn(false);
        $handler = new ChooseGitHubInstallProjectHandler(
            $installSession,
            $container->get(ProjectRepository::class),
            $authorization,
            $container->get(CreateProjectHandler::class),
            $container->get(GitHubPickerListing::class),
            $container->get(AddGitHubRepositoriesHandler::class),
            new NullLogger(),
        );

        try {
            $handler(new ChooseGitHubInstallProjectCommand($owner, (string) $project->id, null));
            self::fail('The voter refusal must stop the handler.');
        } catch (DomainErrors $e) {
            self::assertSame(['project' => 'github.app.flash.project_unavailable'], $e->errors);
        }

        self::assertNull($installSession->pending()?->projectId, 'The refused project is not bound.');
    }
}
