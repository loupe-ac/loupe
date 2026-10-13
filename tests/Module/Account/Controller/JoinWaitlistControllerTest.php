<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class JoinWaitlistControllerTest extends WebTestCase
{
    public function test_gate_open_redirects_to_register(): void
    {
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        $client->request(Request::METHOD_GET, '/waitlist');

        $this->assertResponseRedirects('/register');
    }

    public function test_gate_closed_renders_the_waitlist_form(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Registration is full');
    }

    public function test_joining_creates_an_entry(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'waiting@example.com',
        ]);

        // Post/Redirect/Get: Turbo Drive requires a redirect on a successful
        // top-level form submission, so the controller redirects to the same
        // joined-confirmation branch the OAuth-at-cap flow uses.
        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $client->request(Request::METHOD_GET, '/waitlist?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "You're on the list");

        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('waiting@example.com');
        $this->assertNotNull($entry);
    }

    public function test_duplicate_join_is_silent_and_creates_no_second_row(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'dup-waiting@example.com',
        ]);

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'dup-waiting@example.com',
        ]);

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $client->request(Request::METHOD_GET, '/waitlist?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "You're on the list");

        $entries = static::getContainer()->get(WaitlistEntryRepository::class)->findBy(['email' => 'dup-waiting@example.com']);
        $this->assertCount(1, $entries);
    }

    public function test_the_page_renders_the_email_field_alone_inside_the_notify_frame(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');

        $this->assertSelectorExists('turbo-frame#waitlist-notify form input[type="email"]');
        $this->assertSelectorNotExists('input[type="checkbox"]');
        $this->assertSelectorNotExists('input[name="waitlist_join_form[other]"]');
        $this->assertSelectorExists('input[name="waitlist_join_form[_token]"]');
    }

    public function test_an_invalid_notify_submit_with_picks_re_renders_the_full_form_with_the_picks_kept(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => '', 'picks' => ['gitlab', 'pi'], 'other' => 'Jujutsu'], '/waitlist/notify');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#waitlist-notify .lp-home-ask__form');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="checkbox"][value="gitlab"][checked]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="checkbox"][value="pi"][checked]');
        $this->assertSelectorNotExists('turbo-frame#waitlist-notify input[type="checkbox"][value="cursor"][checked]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[name="waitlist_join_form[other]"][value="Jujutsu"]');
        $this->assertSelectorTextContains('turbo-frame#waitlist-notify', 'This value should not be blank.');
    }

    public function test_an_empty_notify_submit_keeps_the_full_form_that_posts_back_to_notify(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => ''], '/waitlist/notify');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#waitlist-notify form.lp-home-ask__form[action="/waitlist/notify"]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="checkbox"][value="gitlab"]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[name="waitlist_join_form[other]"]');
        $this->assertSelectorTextContains('turbo-frame#waitlist-notify', 'This value should not be blank.');
    }

    public function test_a_notify_submit_joins_and_redirects_to_the_confirmation(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'notify@example.com', 'picks' => ['cursor']], '/waitlist/notify');

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist/notify\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('notify@example.com');
        $this->assertNotNull($entry);
        $this->assertSame(['cursor'], $entry->picks);
    }

    public function test_an_invalid_submit_on_the_waitlist_page_keeps_the_email_only_form(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => '', 'picks' => ['gitlab'], 'other' => 'Jujutsu']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="email"]');
        $this->assertSelectorNotExists('input[type="checkbox"]');
    }

    public function test_the_confirmation_replaces_the_notify_frame(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist?joined=1');

        $this->assertSelectorExists('turbo-frame#waitlist-notify');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function confirmations(): iterable
    {
        yield 'the dialog' => ['/waitlist/modal', 'waitlist_modal_form', 'dialog'];
        yield 'the Notify me form' => ['/waitlist/notify', 'waitlist_join_form', 'notify'];
        yield 'the plain page' => ['/waitlist', 'waitlist_join_form', 'page'];
    }

    #[DataProvider('confirmations')]
    public function test_each_confirmation_names_the_form_that_saved_the_email(string $path, string $formName, string $form): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'named-'.$form.'@example.com'], $path, $formName);
        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, '[data-controller="analytics-join"]');
        $this->assertSelectorExists('[data-controller="analytics-join"][data-analytics-join-form-value="'.$form.'"]');
    }

    #[DataProvider('confirmations')]
    public function test_a_reload_of_the_confirmation_sends_no_second_join(string $path, string $formName, string $form): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'reload-'.$form.'@example.com'], $path, $formName);
        $client->followRedirect();
        $client->request(Request::METHOD_GET, $client->getRequest()->getUri());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "You're on the list");
        $this->assertSelectorNotExists('[data-controller="analytics-join"]');
    }

    #[DataProvider('confirmations')]
    public function test_a_direct_visit_to_the_confirmation_sends_no_join(string $path, string $formName, string $form): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, $path.'?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-controller="analytics-join"]');

        $client->request(Request::METHOD_GET, $path.'?joined=1&t='.str_repeat('a', 32));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-controller="analytics-join"]');
    }

    public function test_a_repeat_submit_of_a_listed_address_shows_the_same_confirmation(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'repeat@example.com'], '/waitlist/modal', 'waitlist_modal_form');
        $this->post($client, ['email' => 'repeat@example.com'], '/waitlist/modal', 'waitlist_modal_form');
        $client->followRedirect();

        $this->assertSelectorExists('[data-controller="analytics-join"][data-analytics-join-form-value="dialog"]');
        $this->assertSame([], $client->getCookieJar()->all());
    }

    public function test_a_refused_submit_carries_no_join_event(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'not-an-email'], '/waitlist/modal', 'waitlist_modal_form');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('turbo-frame#waitlist-modal', 'This value is not a valid email address.');
        $this->assertSelectorNotExists('[data-controller="analytics-join"]');
    }

    public function test_picks_and_text_are_stored_with_the_entry(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'picker@example.com', 'picks' => ['gitlab', 'pi'], 'other' => 'Jujutsu']);

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('picker@example.com');
        $this->assertNotNull($entry);
        $this->assertSame(['gitlab', 'pi'], $entry->picks);
        $this->assertSame('Jujutsu', $entry->otherPick);
    }

    public function test_a_second_join_adds_picks_to_the_same_row(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'again@example.com', 'picks' => ['gitlab']]);
        $this->post($client, ['email' => 'again@example.com', 'picks' => ['cursor']]);

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $entries = static::getContainer()->get(WaitlistEntryRepository::class)->findBy(['email' => 'again@example.com']);
        $this->assertCount(1, $entries);
        $this->assertSame(['gitlab', 'cursor'], $entries[0]->picks);
    }

    public function test_a_pick_that_is_not_a_chip_is_refused(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'bogus@example.com', 'picks' => ['svn']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull(static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('bogus@example.com'));
    }

    public function test_a_text_over_200_characters_is_refused(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'long@example.com', 'other' => str_repeat('a', 201)]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull(static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('long@example.com'));
    }

    public function test_the_modal_form_renders_the_email_field_alone_inside_the_modal_frame(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist/modal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#waitlist-modal form[action="/waitlist/modal"] input[name="waitlist_modal_form[email]"]');
        $this->assertSelectorNotExists('input[type="checkbox"]');
        $this->assertSelectorNotExists('input[name="waitlist_modal_form[other]"]');
    }

    public function test_a_modal_submit_joins_and_redirects_to_the_modal_confirmation(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'modal@example.com'], '/waitlist/modal', 'waitlist_modal_form');

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist/modal\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('modal@example.com');
        $this->assertNotNull($entry);
        $this->assertSame([], $entry->picks);

        $client->request(Request::METHOD_GET, '/waitlist/modal?joined=1');
        $this->assertSelectorTextContains('turbo-frame#waitlist-modal', "You're on the list");
        $this->assertSelectorNotExists('turbo-frame#waitlist-notify');
    }

    public function test_an_invalid_modal_submit_re_renders_the_modal_form_with_the_error(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'not-an-email'], '/waitlist/modal', 'waitlist_modal_form');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#waitlist-modal form[action="/waitlist/modal"]');
        $this->assertSelectorTextContains('turbo-frame#waitlist-modal', 'This value is not a valid email address.');
        $this->assertNull(static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('not-an-email'));
    }

    public function test_a_modal_submit_joins_while_the_gate_is_open(): void
    {
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        $this->post($client, ['email' => 'open-modal@example.com'], '/waitlist/modal', 'waitlist_modal_form');

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist/modal\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $this->assertNotNull(static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('open-modal@example.com'));

        $client->request(Request::METHOD_GET, '/waitlist/modal?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('turbo-frame#waitlist-modal', "You're on the list");
    }

    public function test_a_notify_submit_joins_while_the_gate_is_open(): void
    {
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        $this->post($client, ['email' => 'open-notify@example.com', 'picks' => ['gitlab']], '/waitlist/notify');

        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('#^/waitlist/notify\?joined=1&t=[0-9a-f]{32}$#', (string) $client->getResponse()->headers->get('Location'));
        $this->assertNotNull(static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('open-notify@example.com'));

        $client->request(Request::METHOD_GET, '/waitlist/notify?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('turbo-frame#waitlist-notify', "You're on the list");
    }

    public function test_the_home_page_forms_show_a_busy_label_while_they_save(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist/modal');
        $this->assertSelectorExists('turbo-frame#waitlist-modal button[type="submit"][data-turbo-submits-with="Saving…"]');

        $this->post($client, ['email' => ''], '/waitlist/notify');
        $this->assertSelectorExists('turbo-frame#waitlist-notify button[type="submit"][data-turbo-submits-with="Saving…"]');
    }

    public function test_the_modal_route_404s_while_sign_up_is_not_allowed(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/waitlist/modal');

        $this->assertResponseStatusCodeSame(404);
    }

    /** @param array<string, mixed> $fields */
    private function post(KernelBrowser $client, array $fields, string $path = '/waitlist', string $formName = 'waitlist_join_form'): void
    {
        $crawler = $client->request(Request::METHOD_GET, $path);
        $token = $crawler->filter('input[name="'.$formName.'[_token]"]')->attr('value');
        $client->request(Request::METHOD_POST, $path, [$formName => [...$fields, '_token' => $token]]);
    }

    private function closeRegistration(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Guarantee at least one user exists, then cap at the resulting count —
        // count(0) < cap(N>0) would leave the gate open with no users seeded.
        $em->persist(new User(fullName: 'Gate Filler', email: 'gate-filler@example.com', password: 'x'));
        $em->flush();

        $userCount = $container->get(UserRepository::class)->countActive();

        $flag = new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $userCount);
        $em->persist($flag);
        $em->flush();
    }
}
