<?php

declare(strict_types=1);

namespace App\Tests\Mercure\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class AnswerLiveRefreshOnLostAccessTest extends WebTestCase
{
    private const array LIVE = ['HTTP_X_LOUPE_LIVE_REFRESH' => '1'];

    public function test_a_signed_out_live_refresh_gets_401_instead_of_the_login_redirect(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/projects', server: self::LIVE);

        $this->assertResponseStatusCodeSame(401);
        $this->assertResponseHeaderSame('X-Loupe-Live-Lost', 'signed-out');
    }

    public function test_a_signed_out_page_load_still_redirects_to_the_login_page(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/projects');

        $this->assertResponseRedirects('/login');
    }

    public function test_a_suspended_user_live_refresh_gets_401(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('suspended-live@example.com', suspended: true));

        $client->request(Request::METHOD_GET, '/account/profile', server: self::LIVE);

        $this->assertResponseStatusCodeSame(401);
        $this->assertResponseHeaderSame('X-Loupe-Live-Lost', 'signed-out');
    }

    public function test_a_live_refresh_of_a_deleted_project_gets_410(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('gone-live@example.com'));

        $client->request(Request::METHOD_GET, '/projects/'.Uuid::v7().'/activity', server: self::LIVE);

        $this->assertResponseStatusCodeSame(410);
        $this->assertResponseHeaderSame('X-Loupe-Live-Lost', 'project-deleted');
    }

    public function test_a_live_refresh_of_an_existing_project_is_left_alone(): void
    {
        $client = static::createClient();
        $owner = $this->user('present-live@example.com');
        $client->loginUser($owner);
        $project = $this->project($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity', server: self::LIVE);

        $this->assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('X-Loupe-Live-Lost'));
    }

    public function test_a_page_load_of_a_deleted_project_keeps_its_404(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('gone-load@example.com'));

        $client->request(Request::METHOD_GET, '/projects/'.Uuid::v7().'/activity');

        $this->assertResponseStatusCodeSame(404);
    }

    public function test_a_live_refresh_of_a_project_route_keyed_on_a_project_id_gets_410(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('gone-id@example.com'));

        $client->request(Request::METHOD_GET, '/projects/'.Uuid::v7().'/board/cards/'.Uuid::v7(), server: self::LIVE);

        $this->assertResponseStatusCodeSame(410);
        $this->assertResponseHeaderSame('X-Loupe-Live-Lost', 'project-deleted');
    }

    public function test_a_live_refresh_of_a_project_route_keyed_on_id_gets_410(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('gone-raw-id@example.com'));

        $client->request(Request::METHOD_GET, '/projects/'.Uuid::v7().'/artifacts/'.Uuid::v7(), server: self::LIVE);

        $this->assertResponseStatusCodeSame(410);
        $this->assertResponseHeaderSame('X-Loupe-Live-Lost', 'project-deleted');
    }

    private function project(User $owner): Project
    {
        $em = $this->em();
        $project = new Project($owner, 'Live project');
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(string $email, bool $suspended = false): User
    {
        $em = $this->em();
        $user = new User(fullName: 'Live User', email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        if ($suspended) {
            $user->suspendedAt = new \DateTimeImmutable();
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
