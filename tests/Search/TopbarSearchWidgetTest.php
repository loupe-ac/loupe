<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TopbarSearchWidgetTest extends WebTestCase
{
    public function test_a_page_with_no_top_bar_actions_shows_the_full_field_with_its_contract(): void
    {
        [$client, $project] = $this->signedInProject('widget-full@example.com');
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        $widget = $crawler->filter('.lp-topbar .lp-topsearch[data-controller="topbar-search"]');
        self::assertCount(1, $widget);
        self::assertStringNotContainsString('lp-topsearch--compact', (string) $widget->attr('class'));
        self::assertSame('/projects/'.$project->id.'/search', $widget->attr('data-topbar-search-url-value'));
        foreach (['icon', 'form', 'field', 'frame', 'status', 'error', 'panel'] as $target) {
            self::assertCount(1, $widget->filter('[data-topbar-search-target="'.$target.'"]'), $target);
        }
        self::assertSame('combobox', $widget->filter('[data-topbar-search-target="field"]')->attr('role'));
        self::assertSame('topbar-search-panel', $widget->filter('[data-topbar-search-target="field"]')->attr('aria-controls'));
        self::assertSame('listbox', $widget->filter('[data-topbar-search-target="panel"]')->attr('role'));
        self::assertCount(1, $widget->filter('turbo-frame#topbar-search-results'));
        self::assertCount(0, $crawler->filter('[data-controller~="global-search"]'));
        self::assertLessThan(
            strpos($crawler->filter('.lp-topbar')->html(), 'lp-topbar__new-work'),
            strpos($crawler->filter('.lp-topbar')->html(), 'lp-topsearch'),
            'the search sits left of New work',
        );
    }

    public function test_a_page_that_fills_the_top_bar_actions_shows_the_icon_form(): void
    {
        [$client, $project, $documentId] = $this->signedInProject('widget-compact@example.com', withDocument: true);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-topbar .lp-topsearch.lp-topsearch--compact[data-controller="topbar-search"]'));
    }

    public function test_the_search_without_a_project_points_at_the_owned_projects_route(): void
    {
        [$client] = $this->signedInProject('widget-owned@example.com');
        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertSame('/search', $crawler->filter('.lp-topsearch')->attr('data-topbar-search-url-value'));
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, Project, string}
     */
    private function signedInProject(string $email, bool $withDocument = false): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: $email, password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, self::getContainer());
        $project = new Project($owner, 'Widget project');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();
        $documentId = '';
        if ($withDocument) {
            $documentId = (string) (self::getContainer()->get(CreateDocumentHandler::class))(new CreateDocumentCommand($project, 'Widget document', 'body'))->id;
        }
        $em->clear();
        $client->loginUser($owner);

        return [$client, $project, $documentId];
    }
}
