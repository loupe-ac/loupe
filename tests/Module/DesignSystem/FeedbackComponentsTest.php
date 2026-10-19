<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class FeedbackComponentsTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_flash_carries_its_severity_message_and_dismiss(): void
    {
        $html = $this->render('<twig:Ds:Flash severity="error" dismissLabel="Dismiss">Broke</twig:Ds:Flash>');

        self::assertStringContainsString('class="lp-flash lp-flash--error"', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('data-controller="flash"', $html);
        self::assertStringContainsString('<span class="lp-flash__message">Broke</span>', $html);
        self::assertStringContainsString('>Dismiss</button>', $html);
    }

    public function test_a_flash_without_a_label_has_no_dismiss_button(): void
    {
        self::assertStringNotContainsString('<button', $this->render('<twig:Ds:Flash severity="info">Hi</twig:Ds:Flash>'));
    }

    public function test_a_flash_with_a_title_or_actions_takes_the_block_form(): void
    {
        $html = $this->render('<twig:Ds:Flash severity="warning" title="Cannot push">Why<twig:block name="actions"><button>Fix</button></twig:block></twig:Ds:Flash>');

        self::assertStringContainsString('class="lp-flash lp-flash--warning lp-flash--block"', $html);
        self::assertStringContainsString('<span class="lp-flash__message"><strong class="lp-flash__title">Cannot push</strong>Why<span class="lp-flash__actions"><button>Fix</button></span></span>', $html);
        self::assertStringContainsString('lp-flash--block', $this->render('<twig:Ds:Flash severity="info" title="T">A</twig:Ds:Flash>'));
        self::assertStringNotContainsString('lp-flash__actions', $this->render('<twig:Ds:Flash severity="info" title="T">A</twig:Ds:Flash>'));
        self::assertStringNotContainsString('lp-flash--block', $this->render('<twig:Ds:Flash severity="info">A</twig:Ds:Flash>'));
    }

    public function test_an_empty_state_on_a_card_adds_its_modifier(): void
    {
        self::assertStringContainsString(
            'class="lp-empty-state lp-empty-state--compact lp-empty-state--on-card"',
            $this->render('<twig:Ds:EmptyState compact onCard body="None" />'),
        );
    }

    public function test_a_compact_empty_state_adds_its_modifier_and_a_smaller_icon(): void
    {
        $html = $this->render('<twig:Ds:EmptyState compact icon="lucide:inbox" body="None" />');

        self::assertStringContainsString('class="lp-empty-state lp-empty-state--compact"', $html);
        self::assertStringContainsString('lp-empty-state__icon lp-icon lp-icon--xl"', $html);
    }

    public function test_an_empty_state_renders_its_parts_and_attributes(): void
    {
        $html = $this->render('<twig:Ds:EmptyState icon="lucide:inbox" iconSize="xl" iconClass="text-accent" title="None" body="Add one" linkHref="/docs" linkLabel="Docs" data-empty><p>Extra</p></twig:Ds:EmptyState>');

        self::assertStringContainsString('class="lp-empty-state"', $html);
        self::assertStringContainsString('data-empty', $html);
        self::assertStringContainsString('lp-empty-state__icon lp-icon lp-icon--xl text-accent', $html);
        self::assertStringContainsString('lp-empty-state__icon lp-icon lp-icon--2xl"', $this->render('<twig:Ds:EmptyState icon="lucide:inbox" />'));
        self::assertStringContainsString('<h2 class="lp-empty-state__title">None</h2>', $html);
        self::assertStringContainsString('<p class="lp-empty-state__body">Add one</p>', $html);
        self::assertStringContainsString('<p>Extra</p>', $html);
        self::assertStringContainsString('<a class="lp-empty-state__link" href="/docs"', $html);
    }

    public function test_a_badge_carries_its_status(): void
    {
        self::assertStringContainsString(
            '<span class="lp-badge lp-badge--approved">Done</span>',
            $this->render('<twig:Ds:Badge status="approved">Done</twig:Ds:Badge>'),
        );
    }

    public function test_a_tag_has_a_tone_a_tag_name_and_a_caller_class(): void
    {
        self::assertStringContainsString('<span class="lp-tag">x</span>', $this->render('<twig:Ds:Tag>x</twig:Ds:Tag>'));
        self::assertStringContainsString(
            '<li class="lp-tag lp-tag--amber extra" data-a>x</li>',
            $this->render('<twig:Ds:Tag tone="amber" as="li" class="extra" data-a>x</twig:Ds:Tag>'),
        );
    }

    public function test_a_status_chip_draws_a_dot_unless_told_not_to(): void
    {
        $with = $this->render('<twig:Ds:StatusChip modifier="ok" label="Fine" />');
        $without = $this->render('<twig:Ds:StatusChip :dot="false">Fine</twig:Ds:StatusChip>');

        self::assertStringContainsString('lp-status-chip lp-status-chip--ok', $with);
        self::assertStringContainsString('lp-status-chip__dot', $with);
        self::assertStringNotContainsString('lp-status-chip__dot', $without);
        self::assertStringContainsString('Fine', $without);
    }

    public function test_a_status_chip_with_a_reason_keeps_its_content_and_dot_setting(): void
    {
        $html = $this->render('<twig:Ds:StatusChip modifier="failed" :dot="false" reason="Exit 2" id="r1">Failed</twig:Ds:StatusChip>');

        self::assertStringContainsString('lp-status-chip--reason', $html);
        self::assertStringContainsString('Failed', $html);
        self::assertStringContainsString('id="r1"', $html);
        self::assertStringNotContainsString('lp-status-chip__dot', $html);
    }

    public function test_a_status_chip_can_be_a_button(): void
    {
        $html = $this->render('<twig:Ds:StatusChip as="button" type="button" modifier="neutral" class="toggle">Go</twig:Ds:StatusChip>');

        self::assertMatchesRegularExpression('/<button class="lp-status-chip lp-status-chip--neutral toggle"[^>]* type="button"|<button[^>]*type="button"[^>]*>/', $html);
        self::assertStringContainsString('</button>', $html);
    }

    public function test_the_catalog_lists_the_feedback_parts_as_enforced(): void
    {
        self::bootKernel();
        $enforced = [];
        foreach (new Catalog()->entries() as $entry) {
            if ($entry->enforced) {
                $enforced[] = $entry->name;
            }
            if (null !== $entry->template) {
                self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir').'/templates/'.$entry->template);
            }
        }

        foreach (['Flash', 'EmptyState', 'Badge', 'Tag', 'StatusChip'] as $name) {
            self::assertContains($name, $enforced);
        }
    }
}
