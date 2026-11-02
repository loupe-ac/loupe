<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WidgetFileTest extends TestCase
{
    public function test_widget_file_exists_and_is_self_contained(): void
    {
        $path = dirname(__DIR__, 3).'/public/live-review/widget.js';
        self::assertFileExists($path);

        $src = (string) file_get_contents($path);
        self::assertStringContainsString('attachShadow', $src);
        // The anchors are the record. A note reads no scalar selector or text.
        self::assertStringNotContainsString('comment.selector', $src);
        self::assertStringContainsString('data-project', $src);
        self::assertStringContainsString('data-context', $src);
        // Read once per request and carried through the answer, never re-read
        // when it lands: an SPA swap or a new choice can change it in between.
        self::assertStringContainsString('const asked = bootMarker();', $src);
        self::assertStringContainsString('encodeURIComponent(asked)', $src);
        self::assertStringContainsString('/api/v1/live-review-widget/cards', $src);
        // Raw hex is correct in this file, which carries its own palette. It is
        // wrong in the picker, which sits inside a themed shadow root: a
        // hardcoded white panel appeared inside the dark widget.
        preg_match_all('/^\s*\.lp-(picker|context)[^{]*\{[^}]*\}/m', $src, $rules);
        self::assertNotEmpty($rules[0]);
        foreach ($rules[0] as $rule) {
            self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $rule, $rule);
        }
        // A note goes to its card through the board, and the old save path
        // only tells a stale copy to reload.
        self::assertStringNotContainsString('{ context: CONTEXT }', $src);
        self::assertStringContainsString("'/api/v1/live-review-widget/feedback',", $src);
        self::assertStringNotContainsString("'/api/live-review/comments',", $src);
        // The widget saves as the reviewer writes; there is no send step to call.
        self::assertStringNotContainsString('/api/live-review/review/submit', $src);

        // The launcher says when the backend is a local build, so a reviewer
        // sees that the comments land in a preview instance. The origin the
        // script already computes is the only source, so production cannot
        // switch it on. The label is a span, never a control.
        self::assertStringContainsString('const LOCAL =', $src);
        self::assertStringContainsString('/(^|\\.)localhost$/.test(BACKEND_HOST)', $src);
        self::assertStringContainsString('/^127\\./.test(BACKEND_HOST)', $src);
        self::assertStringContainsString('/\\.local$/.test(BACKEND_HOST)', $src);
        // A false LOCAL emits no node at all, so the launcher keeps its width.
        self::assertStringContainsString(
            '${LOCAL ? \'<span class="lp-local" id="lp-local" data-tip="Notes save to this local build">Local</span>\' : \'\'}',
            $src,
        );

        // Comments live on the server alone. The widget keeps two choices in
        // the browser, the launcher's corner and where notes go, so two writes
        // are the budget.
        self::assertStringContainsString("'loupe.site-review.corner'", $src);
        self::assertStringContainsString('`loupe-site-review:mode:${BACKEND}:${PROJECT}`', $src);
        self::assertSame(2, substr_count($src, 'localStorage.setItem'));
    }

    public function test_list_request_carries_the_scope_of_the_review(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        // The scope rides next to the context marker on every list request.
        self::assertStringContainsString('encodeURIComponent(scope)', $src);
        self::assertStringContainsString('const scope = scopeOf();', $src);
        self::assertStringContainsString('return `card:${lock.cardId}`;', $src);
        self::assertStringContainsString('return `epic:${target.cardId}`;', $src);
        self::assertStringContainsString('return `cards:${sessionCards.slice(0, SESSION_CARDS_SENT).join(\',\')}`;', $src);

        // The cards a tab made live in session storage, so a new tab starts
        // empty. Local storage keeps its budget of two writes.
        self::assertStringContainsString('`loupe-site-review:session-cards:${BACKEND}:${PROJECT}`', $src);
        self::assertSame(2, substr_count($src, 'sessionStorage.setItem'));
    }

    public function test_panel_views_replace_the_old_flags(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        foreach (['state.composing', 'state.picking', 'state.listExpanded', 'verdict.open'] as $flag) {
            self::assertStringNotContainsString($flag, $src, $flag);
        }
        foreach (['list', 'compose', 'finish', 'destination', 'signin'] as $view) {
            self::assertStringContainsString('data-view="'.$view.'"', $src, $view);
        }
        // Views slide with transform and stay mounted, and reduced motion
        // swaps them with no slide.
        self::assertStringContainsString('transform:translateX(100%)', $src);
        self::assertStringContainsString('prefers-reduced-motion:reduce', $src);
    }

    public function test_note_actions_live_in_menus(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        foreach (['lp-resolve', 'lp-edit', 'lp-del"', 'id="lp-move"', 'id="lp-clear"'] as $gone) {
            self::assertStringNotContainsString($gone, $src, $gone);
        }
        self::assertStringContainsString('id="lp-menu" role="menu"', $src);
        self::assertStringContainsString("setAttribute('role', 'menuitem')", $src);
        self::assertStringContainsString('prefers-reduced-motion:reduce){.lp-panel,.lp-view,.lp-view.is-under,.lp-view.is-away,.lp-menu{', $src);
    }

    public function test_active_launcher_tool_shows_an_accent_disc_inside_the_button(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        // The padding insets the accent, so the disc sits inside the button
        // and the click target keeps its full size.
        self::assertMatchesRegularExpression('/^\s*\.lp-launch-action\.on,[^{]*\{[^}]*padding:[1-9]\d*px;[^}]*background-clip:content-box/m', $src);
    }

    public function test_launcher_tools_keep_the_prototype_click_target(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        self::assertMatchesRegularExpression('/^\s*\.lp-launch-action\{[^}]*width:36px;height:36px;/m', $src);
    }

    public function test_every_widget_control_keeps_its_focus_ring(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        preg_match_all('/^\s*(button:focus-visible,[^{]*)\{outline:2px solid var\(--accent-ink\)/m', $src, $rings);
        self::assertCount(2, $rings[1]);
        foreach ($rings[1] as $ring) {
            $selectors = explode(',', $ring);
            foreach (['button', 'select', 'input', 'textarea', 'a', '[role="button"]'] as $control) {
                self::assertContains($control.':focus-visible', $selectors, $ring);
            }
        }
        self::assertDoesNotMatchRegularExpression('/outline:\s*(none|0)\b/', $src);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function overlaySizes(): iterable
    {
        yield 'remove control' => ['X_SIZE', '.lp-hl-x', 'width'];
        yield 'pin' => ['PIN_SIZE', '.pin', 'width'];
        yield 'quote button' => ['QUOTE_BTN_HEIGHT', '.lp-quote-btn', 'height'];
    }

    #[DataProvider('overlaySizes')]
    public function test_overlay_size_constant_matches_its_css(string $constant, string $selector, string $property): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/public/live-review/widget.js');

        self::assertSame(1, preg_match('/const '.$constant.' = (\d+);/', $src, $js));
        self::assertSame(1, preg_match('/^\s*'.preg_quote($selector, '/').'\{(?:[^}]*?;)?'.$property.':(\d+)px/m', $src, $css));
        self::assertSame($css[1], $js[1]);
    }
}
