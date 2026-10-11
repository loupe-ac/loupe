<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Module\OAuth\Service\ConnectedAppsAdminUserPanel;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Drives a real grant through the panel, rather than a stub of the handler.
 * `GrantRepository` is final readonly and reads through raw SQL, so a stubbed
 * version would prove only that the mapping compiles.
 */
final class ConnectedAppsAdminUserPanelTest extends KernelTestCase
{
    public function test_an_account_with_no_grant_still_gets_a_panel(): void
    {
        self::bootKernel();
        $user = $this->persistUser('nothing-connected@example.com');

        $panel = $this->panel()->panelFor($user);

        // The signature is non-nullable, so "always renders" is enforced by the
        // type. This asserts the empty state an admin actually reads.
        self::assertSame('@OAuth/admin/connected_apps_panel.html.twig', $panel->template);
        self::assertSame([], $panel->context['apps']);
        self::assertSame([], $panel->context['previewTokens']);
    }

    public function test_an_account_with_only_preview_tokens_lists_each_token(): void
    {
        self::bootKernel();
        $user = $this->persistUser('preview-only@example.com');
        $project = $this->persistProject($user, 'Cirrus');
        $cardId = $this->persistCard($project, 'Preview it', 4);

        $this->mintPreviewToken($user, $project, 'pr-1', $cardId);
        $this->mintPreviewToken($user, $project, 'pr-2');

        $panel = $this->panel()->panelFor($user);

        self::assertSame([], $panel->context['apps']);
        self::assertEqualsCanonicalizing([
            ['label' => 'pr-1', 'cardNumber' => 4, 'cardTitle' => 'Preview it', 'project' => 'Cirrus', 'daysLeft' => 14],
            ['label' => 'pr-2', 'cardNumber' => null, 'cardTitle' => null, 'project' => 'Cirrus', 'daysLeft' => 14],
        ], $panel->context['previewTokens']);
    }

    public function test_the_panel_lists_preview_tokens_instead_of_the_empty_line(): void
    {
        self::bootKernel();
        $user = $this->persistUser('preview-render@example.com');
        $project = $this->persistProject($user, 'Cirrus');
        $this->mintPreviewToken($user, $project, 'pr-1', $this->persistCard($project, 'Preview it', 4));

        $panel = $this->panel()->panelFor($user);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render($panel->template, $panel->context);

        self::assertStringNotContainsString('connected-apps-panel-empty', $html);
        self::assertStringContainsString('#4 Preview it', $html);
        self::assertStringContainsString('Cirrus', $html);
        self::assertStringContainsString('Expires in 14 days', $html);
    }

    public function test_a_grant_shows_its_client_scope_and_project(): void
    {
        self::bootKernel();
        $user = $this->persistUser('has-connected@example.com');
        $project = $this->persistProject($user, 'Atlas');

        AgentCredential::tokenFor(static::getContainer(), $user, 'mcp', $project);

        $panel = $this->panel()->panelFor($user);

        $apps = $panel->context['apps'];
        self::assertCount(1, $apps, 'one client holds the grant');
        self::assertSame([['scopes' => ['mcp'], 'allProjects' => false, 'project' => 'Atlas']], $apps[0]['grants']);
    }

    /**
     * The context reaches Account, which must not learn an OAuth type, so every
     * leaf is a scalar. A scope arrives as its backed value and the template
     * builds the translation key from it.
     */
    public function test_the_context_carries_scalars_only(): void
    {
        self::bootKernel();
        $user = $this->persistUser('scalars@example.com');
        $project = $this->persistProject($user, 'Borealis');

        AgentCredential::tokenFor(static::getContainer(), $user, 'mcp', $project);

        $panel = $this->panel()->panelFor($user);

        // Without this the walk below visits nothing and passes on an empty
        // context, which proves nothing about the mapping.
        self::assertNotEmpty($panel->context['apps']);

        $leaves = 0;
        $context = $panel->context;
        array_walk_recursive($context, static function (mixed $leaf) use (&$leaves): void {
            ++$leaves;
            self::assertIsScalar($leaf);
        });
        self::assertGreaterThan(0, $leaves);
    }

    private function panel(): ConnectedAppsAdminUserPanel
    {
        $panel = static::getContainer()->get(ConnectedAppsAdminUserPanel::class);
        self::assertInstanceOf(ConnectedAppsAdminUserPanel::class, $panel);

        return $panel;
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Admin Panel Subject', email: $email, password: 'x');
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function mintPreviewToken(User $owner, Project $project, string $label, ?Uuid $cardId = null): void
    {
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);
        $handler(new MintPreviewTokenCommand($owner, (string) $project->id, $label, $cardId));
    }

    private function persistCard(Project $project, string $title, int $number): Uuid
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $column = new BoardColumn($project, 'Doing', 'doing-'.$number, $number);
        $card = new Card(project: $project, column: $column, title: $title, body: 'Body', number: $number);
        $em->persist($column);
        $em->persist($card);
        $em->flush();

        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }

    private function persistProject(User $owner, string $name): Project
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = new Project(owner: $owner, name: $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }
}
