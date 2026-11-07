<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Insights\View\PublicLinkShareView;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShareExperimentDialogRenderTest extends WebTestCase
{
    use InsightsScenario;

    public function test_an_experiment_that_was_never_shared_offers_share_and_a_closed_switch_with_no_link(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-never');
        $this->seedExperiment($project, 'trial');
        $path = $this->pagePath($project);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertSame('Share…', trim($crawler->filter('.lp-experiment-heading__actions [data-experiment-share]')->text()));
        self::assertCount(1, $crawler->filter('.lp-experiment-heading__actions [data-experiment-share] svg'));
        self::assertCount(1, $crawler->filter('.lp-experiment-heading__actions [data-experiment-archive-toggle] svg'));
        $dialog = $crawler->filter('.lp-experiment-heading [data-share-dialog] dialog');
        self::assertCount(1, $dialog);
        self::assertSame('Share this experiment', trim($dialog->filter('.lp-dialog-title')->text()));
        self::assertCount(1, $dialog->filter('input.lp-switch[role="switch"]'));
        self::assertCount(0, $dialog->filter('input.lp-switch[checked]'));
        self::assertCount(1, $dialog->filter('[data-share-note-field][hidden] textarea[data-share-note]'), 'The note hides while the link is off, and stays in the form.');
        self::assertCount(0, $dialog->filter('input[readonly]'));
        self::assertCount(0, $dialog->filter('[data-experiment-share-archived]'));
        self::assertSame('Saving…', trim($dialog->filter('form .lp-dashboard-share__saving[role="status"]')->text()));
        self::assertCount(0, $crawler->filter('[data-experiment-public] .lp-tag'));
    }

    public function test_a_shared_experiment_shows_the_link_the_note_and_the_public_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-shared');
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial', 'Why we ran it.');
        $this->em()->persist($link);
        $this->em()->flush();
        $code = $link->code;
        $path = $this->pagePath($project);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        $dialog = $crawler->filter('.lp-experiment-heading [data-share-dialog] dialog');
        self::assertCount(1, $dialog->filter('input.lp-switch[checked]'));
        self::assertSame('Why we ran it.', trim($dialog->filter('[data-share-note-field]:not([hidden]) textarea[data-share-note]')->text()));
        $input = $dialog->filter('input[readonly]');
        self::assertMatchesRegularExpression('#^https?://[^/]+/s/'.$code.'$#', (string) $input->attr('value'));
        self::assertSame('Copy link', trim($dialog->filter('[data-clipboard-target="button"]')->text()));
        self::assertSame('Public', trim($crawler->filter('.lp-experiment-heading [data-experiment-public="trial"] .lp-tag')->text()));
    }

    public function test_a_link_that_is_switched_off_hides_its_address_and_its_note_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-off');
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial');
        $link->enabled = false;
        $this->em()->persist($link);
        $this->em()->flush();
        $path = $this->pagePath($project);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        $dialog = $crawler->filter('[data-share-dialog] dialog');
        self::assertCount(0, $dialog->filter('input.lp-switch[checked]'));
        self::assertCount(0, $dialog->filter('input[readonly]'));
        self::assertStringNotContainsString('Turn the switch off', $dialog->text());
        self::assertCount(1, $dialog->filter('[data-share-note-field][hidden] textarea[data-share-note]'));
        self::assertCount(0, $crawler->filter('[data-experiment-public] .lp-tag'));
    }

    public function test_the_dialog_of_an_archived_experiment_says_that_sharing_unarchives_it(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-archived');
        $this->seedExperiment($project, 'trial');
        $archive = static::getContainer()->get(ArchiveExperimentHandler::class);
        self::assertInstanceOf(ArchiveExperimentHandler::class, $archive);
        $archive(new ArchiveExperimentCommand($project, 'trial'));
        $path = $this->pagePath($project);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        $dialog = $crawler->filter('.lp-experiment-heading [data-share-dialog] dialog');
        self::assertSame('This experiment is archived. Sharing it unarchives it.', trim($dialog->filter('[data-experiment-share-archived]')->text()));
        $form = $dialog->filter('form[data-experiment-share-confirm]');
        self::assertCount(1, $form);
        self::assertSame('false', $form->attr('data-turbo'));
        self::assertNull($form->attr('data-controller'));
        self::assertCount(0, $dialog->filter('input.lp-switch'));
        self::assertCount(1, $dialog->filter('textarea[data-share-note]'));
        self::assertSame('Cancel', trim($dialog->filter('button[data-action="click->modal#close"]')->text()));
        self::assertSame('Share and unarchive', trim($dialog->filter('button[type="submit"][data-experiment-share-unarchive]')->text()));

        $noteField = (string) $form->filter('textarea')->attr('name');
        $client->submit($form->form([$noteField => 'Back again.']));

        self::assertResponseRedirects($path);
        $crawler = $client->followRedirect();
        self::assertSelectorNotExists('[data-experiment-archived]');
        self::assertSame('Public', trim($crawler->filter('.lp-experiment-heading [data-experiment-public="trial"] .lp-tag')->text()));
        self::assertSame('Back again.', trim($crawler->filter('.lp-experiment-heading textarea[data-share-note]')->text()));
        self::assertCount(1, $crawler->filter('.lp-experiment-heading [data-share-dialog] input.lp-switch[checked]'));
    }

    public function test_each_row_of_the_list_has_a_share_button_and_the_public_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-list');
        $this->seedExperiment($project, 'trial');
        $this->seedExperiment($project, 'other');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();
        $path = '/projects/'.$project->id.'/analytics/experiments';
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-experiment] [data-experiment-row-share]'));
        self::assertCount(2, $crawler->filter('[data-experiment] [data-share-dialog] dialog'));
        self::assertSame('Public', trim($crawler->filter('[data-experiment="trial"] [data-experiment-public] .lp-tag')->text()));
        self::assertCount(0, $crawler->filter('[data-experiment="other"] .lp-tag'));
        self::assertSame('Share trial', $crawler->filter('[data-experiment="trial"] [data-experiment-row-share]')->attr('aria-label'));
        $ids = $crawler->filter('[data-share-dialog] textarea')->each(static fn ($node): string => (string) $node->attr('id'));
        self::assertCount(2, array_unique($ids));
    }

    public function test_the_flag_off_hides_share_and_the_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-flag');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $page = $this->pagePath($project);
        $list = '/projects/'.$project->id.'/analytics/experiments';
        $this->em()->clear();

        $client->loginUser($project->owner);
        foreach ([$page, $list] as $path) {
            $crawler = $client->request(Request::METHOD_GET, $path);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-share-dialog]'), $path);
            self::assertCount(0, $crawler->filter('[data-experiment-share], [data-experiment-row-share]'), $path);
            self::assertCount(0, $crawler->filter('[data-experiment-public] .lp-tag'), $path);
        }
    }

    public function test_a_blocked_share_disables_the_switch_and_says_why(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xrender-blocked');
        $this->seedExperiment($project, 'trial');
        $this->em()->clear();
        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->pagePath($project));
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('@Insights/_share_experiment_dialog_body.html.twig', [
            'project' => $this->em()->find(Project::class, $project->id),
            'experiment' => 'trial',
            'share' => new PublicLinkShareView(null, PublicShareBlock::FlagOff),
            'error' => null,
            'archived' => false,
        ]);

        self::assertStringContainsString('Public links are switched off on this instance.', $html);
        self::assertMatchesRegularExpression('/<input[^>]*disabled="disabled"[^>]*role="switch"/', $html);
        self::assertMatchesRegularExpression('/<textarea[^>]*disabled="disabled"/', $html);

        $archived = $twig->render('@Insights/_share_experiment_dialog_body.html.twig', [
            'project' => $this->em()->find(Project::class, $project->id),
            'experiment' => 'trial',
            'share' => new PublicLinkShareView(null, PublicShareBlock::FlagOff),
            'error' => null,
            'archived' => true,
        ]);

        self::assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*disabled[^>]*data-experiment-share-unarchive/', $archived);
    }

    private function pagePath(Project $project): string
    {
        return '/projects/'.$project->id.'/analytics/experiments/trial';
    }
}
