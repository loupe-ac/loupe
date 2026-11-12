<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class WaitlistBlockPatternControllerTest extends WebTestCase
{
    public function test_an_admin_adds_a_pattern_and_its_entries_leave_the_list(): void
    {
        [$client, $em] = $this->adminClient('pattern-add-admin@admin-test.example.com');
        $entry = new WaitlistEntry('someone@spam-add.example');
        $em->persist($entry);
        $em->flush();
        $entryId = (string) $entry->id;
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorExists('[data-waitlist-entry-id="'.$entryId.'"]');
        $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');

        $client->submitForm('Add pattern', ['add_waitlist_block_pattern_form[pattern]' => ' *@Spam-Add.example ']);

        $this->assertResponseRedirects('/admin/waitlist/block-patterns');
        $stored = $this->patterns()->findOneByPattern('*@spam-add.example');
        self::assertNotNull($stored);
        self::assertSame('*@spam-add.example', $stored->pattern);

        $crawler = $client->followRedirect();
        $this->assertSelectorExists('[data-waitlist-block-pattern-id="'.$stored->id.'"]');
        self::assertGreaterThanOrEqual(1, (int) $crawler->filter('[data-waitlist-hidden-count]')->attr('data-waitlist-hidden-count'));
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorExists('[data-waitlist-pick-counts]');
        $this->assertSelectorNotExists('[data-waitlist-entry-id="'.$entryId.'"]');
    }

    public function test_each_tab_shows_its_own_content_and_marks_itself_current(): void
    {
        [$client] = $this->adminClient('pattern-tabs-admin@admin-test.example.com');

        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-waitlist-pick-counts]');
        $this->assertSelectorNotExists('[data-waitlist-block-patterns]');
        $this->assertSelectorTextContains('[data-waitlist-tab="entries"][aria-current="page"]', 'Entries');
        $this->assertSelectorExists('[data-waitlist-tab="block-patterns"][href="/admin/waitlist/block-patterns"]:not([aria-current])');

        $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-waitlist-block-patterns]');
        $this->assertSelectorNotExists('[data-waitlist-pick-counts]');
        $this->assertSelectorTextContains('[data-waitlist-tab="block-patterns"][aria-current="page"]', 'Block patterns');
        $this->assertSelectorExists('[data-waitlist-tab="entries"][href="/admin/waitlist"]:not([aria-current])');
    }

    public function test_a_pattern_of_only_stars_is_refused_with_a_field_error(): void
    {
        [$client] = $this->adminClient('pattern-stars-admin@admin-test.example.com');
        $before = \count($this->patterns()->findAll());
        $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');

        $client->submitForm('Add pattern', ['add_waitlist_block_pattern_form[pattern]' => '**']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[data-waitlist-block-pattern-add]', 'at least one character other than *');
        self::assertCount($before, $this->patterns()->findAll());
    }

    public function test_a_duplicate_pattern_is_refused_with_a_field_error(): void
    {
        [$client, $em] = $this->adminClient('pattern-dup-admin@admin-test.example.com');
        $em->persist(new WaitlistBlockPattern('*@dup.example'));
        $em->flush();
        $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');

        $client->submitForm('Add pattern', ['add_waitlist_block_pattern_form[pattern]' => '*@DUP.example']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[data-waitlist-block-pattern-add]', 'This pattern already exists.');
    }

    public function test_an_admin_removes_a_pattern_and_its_entries_show_again(): void
    {
        [$client, $em] = $this->adminClient('pattern-remove-admin@admin-test.example.com');
        foreach ($this->patterns()->findAll() as $existing) {
            $em->remove($existing);
        }
        $entry = new WaitlistEntry('someone@spam-remove.example');
        $pattern = new WaitlistBlockPattern('*@spam-remove.example');
        $em->persist($entry);
        $em->persist($pattern);
        $em->flush();
        $entryId = (string) $entry->id;
        $patternId = (string) $pattern->id;
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorNotExists('[data-waitlist-entry-id="'.$entryId.'"]');
        $crawler = $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');
        $form = $crawler->filter('[data-waitlist-block-pattern-id="'.$patternId.'"] form');
        self::assertNotNull($form->attr('data-turbo-confirm'));

        $client->submit($form->form());

        $this->assertResponseRedirects('/admin/waitlist/block-patterns');
        $em->clear();
        self::assertNull($this->patterns()->find($patternId));
        $client->followRedirect();
        $this->assertSelectorNotExists('[data-waitlist-block-pattern-id="'.$patternId.'"]');
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorExists('[data-waitlist-entry-id="'.$entryId.'"]');
    }

    public function test_a_non_admin_can_neither_add_nor_remove_a_pattern(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pattern = new WaitlistBlockPattern('*@kept.example');
        $em->persist($pattern);
        $em->flush();
        $patternId = (string) $pattern->id;
        $client->loginUser($this->seedUser($em, 'pattern-plain-user@admin-test.example.com', []));

        $client->request(Request::METHOD_GET, '/admin/waitlist/block-patterns');
        $this->assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/admin/waitlist/block-patterns', ['add_waitlist_block_pattern_form' => ['pattern' => '*@new.example']]);
        $this->assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/admin/waitlist/block-patterns/'.$patternId.'/remove');
        $this->assertResponseStatusCodeSame(403);

        $em->clear();
        self::assertNull($this->patterns()->findOneByPattern('*@new.example'));
        self::assertNotNull($this->patterns()->find($patternId));
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, EntityManagerInterface}
     */
    private function adminClient(string $email): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->seedUser($em, $email, ['ROLE_ADMIN']));

        return [$client, $em];
    }

    private function patterns(): WaitlistBlockPatternRepository
    {
        $patterns = static::getContainer()->get(WaitlistBlockPatternRepository::class);
        self::assertInstanceOf(WaitlistBlockPatternRepository::class, $patterns);

        return $patterns;
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: 'x');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
