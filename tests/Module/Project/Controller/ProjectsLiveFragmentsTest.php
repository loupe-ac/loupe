<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Command\ListProjectsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\View\ProjectCursor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The fragment routes that the live regions of the projects list, the Connect page and the readiness settings read. */
final class ProjectsLiveFragmentsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_live_read_with_no_session_answers_401_with_the_cause(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects-live/rows', server: ['HTTP_X_LOUPE_LIVE_REFRESH' => '1']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('X-Loupe-Live-Lost', 'signed-out');
    }

    public function test_every_page_is_a_rows_region_and_only_a_later_page_has_the_band_and_the_cursor(): void
    {
        $owner = $this->user('page-owner@example.com');
        foreach (range(1, ListProjectsHandler::PER_PAGE + 1) as $number) {
            $this->project($owner, 'paged-'.$number);
        }
        $this->em->flush();
        $this->client->loginUser($owner);

        $first = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects');
        $region = $first->filter('[data-live-region-mode-value="rows"]');
        self::assertCount(1, $region);
        self::assertSame('/projects-live/rows?page=1', $region->attr('data-live-region-url-value'));
        self::assertNull($region->attr('data-live-since'));
        self::assertCount(0, $first->filter('#projects-live-band'));
        self::assertCount(0, $first->filter('turbo-frame#projects-frame'));
        self::assertCount(ListProjectsHandler::PER_PAGE, $first->filter('#projects-grid [data-live-row]'));
        self::assertCount(1, $first->filter('#projects-grid #projects-new-tile'));
        self::assertCount(1, $first->filter('#projects-pagination .lp-pagination'));

        $second = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects?page=2');
        $region = $second->filter('[data-live-region-mode-value="rows"]');
        self::assertCount(1, $region);
        self::assertSame('/projects-live/rows?page=2', $region->attr('data-live-region-url-value'));
        self::assertNotSame('', (string) $region->attr('data-live-since'));
        self::assertCount(1, $second->filter('#projects-live-band'));
        self::assertCount(1, $second->filter('[data-live-row]'));
    }

    public function test_the_first_page_answer_morphs_a_shown_row_and_inserts_a_new_one_before_the_row_that_follows_it(): void
    {
        $owner = $this->user('first-rows-owner@example.com');
        $other = $this->user('first-rows-other@example.com');
        $older = $this->project($owner, 'first-older');
        $foreign = $this->project($other, 'first-foreign');
        $this->em->flush();
        $newer = $this->project($owner, 'first-newer');
        $this->em->flush();
        $gone = '0197c8a0-0000-7000-8000-000000000000';

        $this->client->loginUser($owner);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects-live/rows', [
            'page' => 1,
            'ids' => $older->id.','.$foreign->id.','.$gone,
        ], server: ['HTTP_X_LOUPE_LIVE_REFRESH' => '1']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="project-row-'.$older->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="before" target="project-row-'.$older->id.'">', $body);
        self::assertStringContainsString('data-live-row="'.$newer->id.'"', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="project-row-'.$gone.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="project-row-'.$foreign->id.'">', $body);
        self::assertStringNotContainsString('first-foreign', $body);
        self::assertStringNotContainsString('projects-live-band', $body);
        self::assertStringNotContainsString('projects-newer-band', $body);
        self::assertStringContainsString('target="projects-pagination"', $body);
    }

    public function test_the_first_page_answer_puts_the_last_new_row_before_the_new_project_tile(): void
    {
        $owner = $this->user('first-rows-last@example.com');
        $project = $this->project($owner, 'first-last');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects-live/rows', ['page' => 1]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="before" target="projects-new-tile">', $body);
        self::assertStringContainsString('data-live-row="'.$project->id.'"', $body);
        self::assertStringNotContainsString('action="replace"', $body);
    }

    public function test_the_rows_answer_replaces_each_shown_row_removes_the_gone_ones_and_counts_the_newer_ones(): void
    {
        $owner = $this->user('rows-owner@example.com');
        $other = $this->user('rows-other@example.com');
        $shown = $this->project($owner, 'rows-shown');
        $foreign = $this->project($other, 'rows-foreign');
        $this->em->flush();
        $since = ProjectCursor::of($shown)->toString();
        $gone = '0197c8a0-0000-7000-8000-000000000000';
        $this->project($owner, 'rows-newer');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects-live/rows', [
            'page' => 2,
            'ids' => $shown->id.','.$foreign->id.','.$gone,
            'since' => $since,
        ], server: ['HTTP_X_LOUPE_LIVE_REFRESH' => '1']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="project-row-'.$shown->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="project-row-'.$gone.'">', $body);
        // A row of another owner reads like one that is gone, so the answer leaks nothing.
        self::assertStringContainsString('<turbo-stream action="remove" target="project-row-'.$foreign->id.'">', $body);
        self::assertStringNotContainsString('rows-foreign', $body);
        self::assertStringContainsString('data-testid="projects-newer-band"', $body);
    }

    public function test_the_rows_answer_shows_no_band_when_nothing_is_newer(): void
    {
        $owner = $this->user('rows-quiet@example.com');
        $shown = $this->project($owner, 'rows-quiet');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects-live/rows', ['page' => 2, 'ids' => (string) $shown->id, 'since' => ProjectCursor::of($shown)->toString()]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Guard: the row stream is there, so the missing band is a result and not an empty answer.
        self::assertStringContainsString('target="project-row-'.$shown->id.'"', $body);
        self::assertStringNotContainsString('projects-newer-band', $body);
    }

    public function test_the_connect_machines_frame_answers_the_viewer_and_refuses_a_stranger(): void
    {
        $owner = $this->user('machines-owner@example.com');
        $stranger = $this->user('machines-stranger@example.com');
        $project = $this->project($owner, 'machines');
        $this->em->flush();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/connect/machines');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#connect-machines[refresh="morph"]'));

        $this->client->loginUser($stranger);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/connect/machines');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_discovery_frame_answers_the_manager_and_refuses_a_stranger(): void
    {
        $owner = $this->user('discovery-owner@example.com');
        $stranger = $this->user('discovery-stranger@example.com');
        $project = $this->project($owner, 'discovery-frame');
        $this->em->flush();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/readiness/discovery');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#readiness-discovery[refresh="morph"]'));

        $this->client->loginUser($stranger);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/readiness/discovery');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_fingerprint_changes_with_a_setting_and_is_refused_to_a_stranger(): void
    {
        $owner = $this->user('fingerprint-owner@example.com');
        $stranger = $this->user('fingerprint-stranger@example.com');
        $project = $this->project($owner, 'fingerprint');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/fingerprint');
        self::assertResponseIsSuccessful();
        $before = $this->client->getResponse()->getContent();
        self::assertNotEmpty($before);

        $project->agentGitHubLogin = 'loupe-agent';
        $this->em->flush();
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/fingerprint');
        self::assertNotSame($before, $this->client->getResponse()->getContent());

        $this->client->loginUser($stranger);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/fingerprint');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param non-empty-string $page
     * @param non-empty-string $form
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formPages')]
    public function test_each_edit_form_sits_in_a_band_region_for_project_updated(string $page, string $form): void
    {
        $owner = $this->user('band-'.md5($page).'@example.com');
        $project = $this->project($owner, 'band-'.md5($page));
        $this->em->flush();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.$page);

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('[data-controller="live-region"][data-live-region-mode-value="band"]');
        self::assertCount(1, $region);
        self::assertSame('["project.updated"]', $region->attr('data-live-region-types-value'));
        self::assertCount(1, $region->filter('form'.$form));
        self::assertSame('/projects/'.$project->id.'/settings/fingerprint', $region->attr('data-live-region-url-value'));
        // The page renders the fingerprint that the check route answers, so a matching reconnect shows no band.
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/fingerprint');
        self::assertSame($region->attr('data-live-fingerprint'), $this->client->getResponse()->getContent());
        $band = $region->filter('[data-live-region-target="band"]');
        self::assertCount(1, $band);
        self::assertNotNull($band->attr('hidden'));
        self::assertSame('_blank', $band->filter('a')->attr('target'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function formPages(): iterable
    {
        yield 'project form' => ['/edit', '.lp-form'];
        yield 'allowed origins' => ['/connect', '[data-testid="allowed-origins-form"]'];
        yield 'readiness guide' => ['/settings/readiness', '.lp-form'];
        yield 'agent account' => ['/readiness/agent-account', '.lp-form'];
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);

        return $project;
    }
}
