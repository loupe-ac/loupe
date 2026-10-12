<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->assertResponseRedirects('/waitlist?joined=1');
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

        $this->assertResponseRedirects('/waitlist?joined=1');
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

    public function test_an_invalid_submit_with_picks_re_renders_the_full_form_with_the_picks_kept(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => '', 'picks' => ['gitlab', 'pi'], 'other' => 'Jujutsu']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#waitlist-notify .lp-home-ask__form');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="checkbox"][value="gitlab"][checked]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[type="checkbox"][value="pi"][checked]');
        $this->assertSelectorNotExists('turbo-frame#waitlist-notify input[type="checkbox"][value="cursor"][checked]');
        $this->assertSelectorExists('turbo-frame#waitlist-notify input[name="waitlist_join_form[other]"][value="Jujutsu"]');
        $this->assertSelectorTextContains('turbo-frame#waitlist-notify', 'This value should not be blank.');
    }

    public function test_an_invalid_submit_without_picks_keeps_the_email_only_form(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => '']);

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

    public function test_picks_and_text_are_stored_with_the_entry(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $this->post($client, ['email' => 'picker@example.com', 'picks' => ['gitlab', 'pi'], 'other' => 'Jujutsu']);

        $this->assertResponseRedirects('/waitlist?joined=1');
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

        $this->assertResponseRedirects('/waitlist?joined=1');
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

    /** @param array<string, mixed> $fields */
    private function post(KernelBrowser $client, array $fields): void
    {
        $crawler = $client->request(Request::METHOD_GET, '/waitlist');
        $token = $crawler->filter('input[name="waitlist_join_form[_token]"]')->attr('value');
        $client->request(Request::METHOD_POST, '/waitlist', ['waitlist_join_form' => [...$fields, '_token' => $token]]);
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
