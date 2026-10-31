---
name: project-frontend
description: "Use when working on `assets/styles/app.css`, Stimulus controllers, Turbo patterns, modals or frontend visual behaviour, or when a Tailwind class or the Tailwind watcher misbehaves."
---

# Frontend: CSS, Stimulus, Turbo, and animations

## Semantic CSS class layer

Define every component style in the semantic class layer in `app.css`, with `@apply` and Tailwind utilities inside the class. Templates use those semantic classes, never raw Tailwind utilities or inline styles.

Search `app.css` for an existing class before you add one. Put new component classes in `@layer components` with a doc comment that says what the class is and when to use it. Use raw CSS only where `@apply` has no equivalent: multi-layer backgrounds with CSS variables, pseudo-elements, `-webkit-appearance`, and SVG data URIs.

Keep design tokens (colors, gradients, backgrounds) as custom properties in `assets/styles/tokens.css` and reference them inside class definitions. Do not hardcode them in templates.

## Tailwind v4 quirks and constraints

The dev container rebuilds Tailwind CSS on its own, so never run `bin/console tailwind:build` by hand after you edit a template or `app.css`. The watcher picks a change up within a second or two. When a class does not appear in the compiled CSS, wait and re-check rather than reaching for a manual build. Run `bin/console tailwind:build` in CI scripts and plan verify steps only. The same applies to `cache:clear`, which dev does not need.

The `tailwind` compose service is the watcher. Observe it with `docker compose logs tailwind`, or run it in the foreground with `just tailwind`. Never run both, because two watchers write the same file and race. The `tailwind` service watches the main checkout only, because empty mounts hide `.worktrees/` and `.claude/worktrees/` from it. The `tailwind-worktrees` service rebuilds the worktree sheets.

Define a modifier class (a size or icon variant of a button) after the base class it modifies. Inside `@layer components` the later definition wins at equal specificity, so a modifier defined first is silently overridden.

`@apply` does not support `vh`/`vw`/`dvh` units. Add a raw CSS line for the viewport-relative value beside the `@apply`.

Tailwind v4 emits CSS only for class names it sees statically. A template that interpolates a class name (`class="alert-{{ severity }}"`, `class="rounded-{{ token }}"`) builds permutations no template literal contains, so Tailwind silently drops them. List every interpolated class in the `<div class="hidden ...">` hint div near the bottom of `templates/base.html.twig`. Keep that div the single source of truth, and do not scatter safelist hacks across templates.

Tailwind v4 does not emit `--radius-full`. Every other `rounded-{token}` produces a `--radius-{token}` custom property, but `rounded-full` compiles straight to `border-radius: calc(infinity * 1px)`, so `var(--radius-full)` resolves to nothing. Declare it in `@theme` in `app.css` when you need it:

```css
--radius-full: calc(infinity * 1px);
```

Adding `rounded-full` to the hint div does not fix this. Only the `@theme` declaration does.

Tailwind scans documentation, not only templates. Source detection walks every non-gitignored file in addition to the `@source` list, so a utility class merely named in `AGENTS.md`, a `SKILL.md` or a design note is compiled into the shipped stylesheet. `blur-[1.25rem]` stayed in `app.built.css` after every real use was removed, because this skill cites it as an example. `app.css` therefore excludes `.agents`, `.claude`, worktrees and `docs` with `@source not`. Keep those exclusions. Without them, prose inflates the CSS bundle and invalidates any verification that reads the compiled output, because a class you just deleted still appears there.

Unlayered CSS in `app.css` always wins over layered rules at any specificity, `@layer components` and library layers included. Use unlayered declarations to override library component styles.

## General CSS patterns

Do not override a component class's `justify-*` with another `justify-*` utility on the same element; the result is unreliable. Use an auto-margin on a child instead. `mr-auto` on the first child pushes the later siblings right. `ml-auto` on the last child pushes it right. This sidesteps `justify-content` entirely.

Keep every layout container max-width in sync on the Tailwind scale, and never hardcode pixel values. Mismatched max-widths misalign anchored dropdowns against page-body elements.

Wrap form submit buttons in a flex row as the form's last child: `flex items-center justify-end gap-3 pt-2`. Put a secondary link first in that row with `mr-auto`, which pins it left while the button stays right. Use responsive flexbox utilities when the row must stack on mobile. Use `sm:order-1` and `sm:order-2` when the DOM order must differ between mobile and desktop, and do not duplicate elements.

An absolutely positioned child's containing block is the parent's padding box, and that box includes the padding. So `right: 0` puts the child's right edge level with the parent's border, and the parent's `padding-right` does not inset it. Restate the padding on the child to line a panel up with the padded content (`right-7` under a `px-7` parent). This entry once claimed the opposite and was measured wrong, so verify with `getBoundingClientRect()`.

Style an open `<details>` with `details[open] > .child-class { ... }` in `app.css`. No Stimulus controller or JS is needed.

For tab bars and toolbars that overflow on small screens, add `overflow-x: auto`, `scrollbar-width: none` and `::-webkit-scrollbar { display: none }`. Add `scroll-padding-inline: 1rem` so `scrollIntoView` leaves room at the edges. Add `white-space: nowrap` when items must not wrap.

When a component hides a native input (`opacity-0 w-0 h-0`) and uses a label as the visual target, put the focus ring on the wrapper with `:has(input:focus-visible)`. Do not use `:focus`, which fires on a mouse click too.

```css
.my-custom-control:has(input:focus-visible) {
  @apply outline-2 outline-offset-2 outline-slate-500;
}
```

This applies to any custom control that visually replaces a native input.

Give every button variant a matching `:active` block, such as `translate-y-px scale-97` with a darkened background or a reduced shadow. Include `transition` in the base class, or the press snaps.

Show the difference between irreversible and recoverable destructive actions at a glance. A permanent action such as account deletion gets a danger-zone treatment: a striped or heavily bordered card with a solid red button. A recoverable action such as removing an integration or deleting a recoverable record gets a softer style: a ghost or outline button in orange. Use an inline `<details><summary>` disclosure as the confirmation step for recoverable destructive actions on settings pages. Reserve `<dialog>` modals for irreversible actions.

Read CSS custom properties from `<body>` or any ancestor with `getComputedStyle(element).getPropertyValue('--my-var').trim()` in a Stimulus controller. This feeds theme-matched values into canvas drawing and WAAPI animations.

## Modals

Open a `<dialog>` modal from a Stimulus controller.

- Wrap the trigger and the dialog together in a `data-controller="modal"` div.
- Add `data-modal-target="dialog"` on the `<dialog>` element.
- Add `data-action="click->modal#open"` on the trigger button or link.
- Close with `data-action="click->modal#close"`, which runs the slide-out animation.
- Never call `dialog.close()` directly.
- Never use `<form method="dialog">`. The browser still calls `dialog.close()` natively, even with the Stimulus action.
- Never use `<a href="...">` as a cancel trigger. Turbo fires the navigation mid-animation.
- Write every cancel button as `<button type="button" data-action="click->modal#close">`.
- Never call `showModal()` from an inline `onclick`.

Animate the dialog element with WAAPI in the controller, not CSS animations. Only the `::backdrop` uses CSS animations.

Keep `csrf_protection_controller.js` eager (`/* stimulusFetch: 'eager' */`). Login and other forms use `input[name="_csrf_token"]` with no `data-controller` attribute, so a lazily loaded controller never activates and its document-level `submit` listener never registers. Do not change it to `'lazy'`.

A controller marked `/* stimulusFetch: 'lazy' */` is fetched asynchronously, which leaves a gap between DOM render and `connect()`. An element that relies only on a WAAPI entry animation in `connect()` flashes at full opacity first. Add a CSS `animation` with `animation-fill-mode: both` on its base class, so CSS handles entry and WAAPI handles exit only.

## JavaScript conventions

Use full descriptive variable names in JavaScript and TypeScript, and never abbreviate. Write `this._resizeObserver` not `this._ro`, `context` not `ctx`, `devicePixelRatio` not `dpr`, `remaining` not `rem`. The code is minified for production, so source readability takes priority.

## Stimulus patterns

When a controller needs different behaviour on mobile and desktop, such as different WAAPI keyframes, use `const isMobile = () => window.innerWidth < 640`, matching Tailwind's `sm:` breakpoint. Evaluate it at interaction time inside the event handler, not in `connect()`, so it stays correct after an orientation change.

A CSS `max-height` transition animates a `<details>` for a simple one-shot disclosure. Use WAAPI in a Stimulus controller for repeated open and close, because CSS transitions silently stop working after the first cycle. Wrap the collapsible content in a `rounded-xl overflow-hidden` container to clip the animation.

`connect()` fires via MutationObserver before the browser computes layout during Turbo navigation, so `getBoundingClientRect()` returns `(0, 0)`. Never do geometry-dependent setup synchronously in `connect()`. Use a `ResizeObserver`: its initial callback always fires after layout, so it is the correct entry point for size-dependent canvas or element initialization.

Setting `canvas.width` clears the canvas. ResizeObserver callbacks fire after `requestAnimationFrame` in the rendering pipeline, so a resize inside the callback leaves a blank canvas in the paint queue until the next rAF. Call your draw function immediately after you resize the canvas inside the callback, rather than waiting for the next frame.

Read `references/board-drag.md` before you change the board drag controller, or before you write any pointer-driven drag with an optimistic update. It carries the 5-pixel threshold, the three pointer traps, `turbo:submit-end` as the one failure hook, restore-by-following-card, and the test race an optimistic move introduces.

## Turbo patterns

Never submit through `fetch()` or JS. Send every mutation through a plain `<form method="POST">`, or a Symfony form, which is preferred, and Turbo handles the async submit and the in-place update. A hand-rolled `fetch()` POST in a Stimulus controller bypasses the eager document-level `submit` listener in `csrf_protection_controller.js`, so you must re-implement the stateless double-submit CSRF dance by hand, a recurring source of silent 403s. It also duplicates the error handling Turbo gives you free. Reach for `fetch()` only for a genuine non-form interaction with no good Turbo equivalent, and that bar is high. A fieldless action such as "resolve" is still a `<form>` with a submit button and a CSRF token, only without a Symfony FormType. Return a Turbo Stream for an in-place update without a full-page visit, or scope the form to a `<turbo-frame>`.

Return the Turbo Stream directly from the controller. Do not extract a responder or stream builder service.

```php
if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
    return $this->redirectToRoute('app_…', ['id' => (string) $entity->id]); // no-JS / non-Turbo fallback
}
$html = $this->renderView('area/_thing.stream.html.twig', [...]);
return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
```

- Always provide the Post/Redirect/Get fallback for non-Turbo requests.
- Name stream templates `_<name>.stream.html.twig`. The leading `_` marks them partials, so gamache's `ControllerTemplateNameRule` skips the controller, and `.stream.` documents intent.
- Wrap stream content in `<turbo-stream action="replace|update|append" target="dom-id">…</turbo-stream>`. The targeted element must render its own matching `id`.
- Accept the minor per-controller duplication of the format check and redirect. It is the conventional shape and reads more clearly than a shared abstraction.

Turbo Drive discards a 200 HTML response to a form submission. It reads the 200 as a successful navigation, so the browser stays on the current page and renders nothing. On a non-frame submission Turbo enforces `requestMustRedirect` and discards the response with a console error. WebTestCase sees a perfectly valid response, so only e2e catches it.

- A successful top-level form POST must redirect with a 302. Rendering a confirmation view directly on POST success is always a bug, so redirect to a route that renders the confirmation.
- A validation failure must re-render with HTTP 422, because only a 4xx or 5xx response makes Turbo render the body in place.

```php
return $this->forward(SomeController::class, $params)
    ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
```

Add `data-turbo-frame="_top"` to a form inside a `<turbo-frame>` that redirects page-level. Without it the redirect is frame-scoped: the frame morphs in place and flash messages rendered outside the frame are silently dropped. The vendor admin-bundle list frames set this on their own forms, and any form you add inside a frame must do the same, unless an in-frame update is what you want.

Turbo Stream animations are opt-in. Hook `turbo:before-stream-render` globally to fade elements in and out, and add a `data-` attribute to opt a target element in.

Turbo 8 prefetches links on hover by default, issuing a real GET to the `href`. That prefetch fires the side effect of any "magic" GET that mutates state, and `logout` is the canonical case: a hover silently logs the user out. Add `data-turbo-prefetch="false"` to such links.

```twig
<a href="{{ path('app_logout') }}" data-turbo-prefetch="false">Log out</a>
```

Prefer a POST endpoint for a state change. An unavoidable GET link with side effects must opt out of prefetch.

## Design system

Never use arbitrary CSS var values in markup: no `[var(--accent)]` or other bracketed `var(...)` value in a template. Use the named design-token and semantic Tailwind classes instead, because every token is exposed as a named utility. gamache's `NoArbitraryValuesCheck` enforces this.

Use no arbitrary values in `app.css` either. Every size, colour and spacing value must resolve to a named Tailwind token. Bracket values such as `w-[45rem]` and `blur-[1.25rem]` are not acceptable in `@apply` or as bare CSS properties. Round to the nearest named token.

Some conversions are exact and some are not, and the difference matters when you report the change. `w-[45rem]` gives `w-180` and `-top-[12.5rem]` gives `-top-50`, both exact, because the spacing scale is `0.25rem × n`. `blur-[1.25rem]` gives `blur-xl`, which is not exact: the blur scale has no 1.25rem step, so 20px becomes 24px. Accepting that change is correct. Presenting it as equivalent is not.

Treat a custom value as a mistake to fix, not a token to add. Do not invent a `@theme` token to preserve an off-scale value, and do not fall back to an arbitrary value. Snap to the nearest existing token and accept the pixel change. A custom token is the same problem as a bracket value wearing a different hat. The one place a value is set is `assets/styles/tokens.css`, where the radius, spacing and motion groups sit beside the colour, type and shadow groups. `/styleguide` draws every token, in dev. Add a literal `px` or `rem` to `app.css` only inside a token block. `docs/contributing/design-system.md` covers the system.

Several installed user-level design skills hand you literal arbitrary utilities: `beautiful-shadows` gives three `shadow-[0px_2px_3px_-1px_rgba(...)…]` strings, and `glass-dark-ui` one more. Pasting those into a template fails `NoArbitraryValuesCheck`. They are still worth using, because a six-layer box-shadow is genuinely non-expressible as a single utility, like the multi-layer `background:` gradients carved out above. Land the value as a `--shadow-*` entry in the `@theme` design-token block and reference it from a semantic class in `@layer components`. Never write it as a bracket utility in Twig, and never as a one-off literal inside the class body. Snapping such a shadow to `shadow-md` instead is also legitimate.

Prefer the named scale over numeric spacing multiples: `max-w-sm` and `max-w-5xl`, not `max-w-102` and `max-w-270`, even though the numeric forms are exact and the named ones are not. Proportion consistency matters more than preserving a hand-picked width.

A `text-*` utility carries a bundled `line-height`, so place it before `leading-none` inside `@apply`, or the size overrides the leading.

Derive the value from the Tailwind spacing token when a CSS property has no utility equivalent, such as `grid-template-columns` or `background-size`: `calc(var(--spacing) * n)`. This keeps the value on the scale without a raw literal.

The only acceptable raw CSS in `@layer components` is genuinely non-expressible as utilities: multi-layer `background:` gradients that reference CSS variables, `mask-image` and `-webkit-mask-image`, and the viewport-relative lengths (`vh`/`dvh`) that `@apply` cannot emit.

A semantic color token in `@theme` that maps to a Tailwind palette color uses `var(--color-{palette}-{shade})`, not a raw hex value: `--color-danger: var(--color-red-700)`, not `--color-danger: #c83a3a`. Brand palette tokens are the exception, because the values in `:root`, `.theme-light` and `.theme-dark` define the source of truth and may use raw hex or rgba.

Do not add new element style rules to `@layer base`. Tailwind's preflight handles the HTML reset for every element, `dialog` included, and component-specific styles belong in `@layer components` or as utilities in templates. An existing `@layer base` rule may have its raw CSS values converted to `@apply` during a normalisation pass, because converting is not the same as adding a new rule.

Spell out every word in a CSS semantic or utility class name: `.sidebar-item` not `.sb-item`, and `.sidebar-footer` not `.sb-foot`. This applies to the `app.css` definitions and to the usages in templates.

### Building blocks

Use a component from `src/Module/DesignSystem/Catalog.php` before you write markup for a shared control. When a component does not fit, change the component in the same branch, and never write a page-level copy of it. A change to the look or the behaviour of a component goes into the component, as a new option, size or state. The position of a component on the page, such as its margin, its width in a grid or its order, stays a page rule. When the card has a product document with a Design system section, follow its list. When you need a component change that the list lacks, ask the owner through the inbox. Add no new component unless the card asks for one. Write no hand-made `lp-*` root class for a block the catalog lists, because `just gamache` blocks it once the block is enforced. `/styleguide` draws each one in dev, and `docs/contributing/design-system.md` lists them.

1. Button: `<twig:Ds:Button variant="primary" size="sm" href="...">`. Variants are primary, inverse, outline, success, danger, ghost, danger-ghost, icon, compact, open and on-card, and several may be given with a space. A Symfony form button takes `ds_button_class('primary')` in its `attr.class`. The CSS is `assets/styles/components/button.css`.
2. Flash: `<twig:Ds:Flash severity="error" :dismissLabel="...">message</twig:Ds:Flash>`. Severities are success, error, warning and info. A `title` prop and an `actions` block make the block form, which keeps the dot on the first line and puts the buttons in a row under the message.
3. EmptyState: `<twig:Ds:EmptyState icon="lucide:inbox" :title="..." :body="..." linkHref linkLabel>`. The content goes after the body. `compact` fits a section inside a page, and `onCard` draws a bordered card on the app fill inside a panel.
4. Badge: `<twig:Ds:Badge :status="document.status.value">`, for in-review, draft, approved and changes-requested.
5. Tag: `<twig:Ds:Tag tone="amber">`, with `as="li"` inside a list. Tones are neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink and orange.
6. StatusChip: `<twig:Ds:StatusChip modifier="ok" :label="..." :reason="...">`. It takes `:dot="false"` and `as="button"`, and its content is the label when you pass no `label`.
7. Dialog: `<twig:Ds:Dialog size="document" aria-labelledby="..." data-action="cancel->modal#close">`. The size is document or search, and optional. The component sets `data-modal-target="dialog"`. Keep `lp-dialog-title`, `lp-dialog-desc` and `lp-dialog-actions` as plain classes inside. The CSS is `assets/styles/components/dialog.css`.
8. Tabs: `<twig:Ds:Tabs aria-label="...">` with `lp-tabs__tab` links or buttons inside. Pass `tag="div"` and `role="tablist"` for a tab list. The CSS is `assets/styles/components/tabs.css`.
9. Pagination: `<twig:Ds:Pagination route="..." :page="page" :totalPages="totalPages" :pageList="pageList" />`. The CSS is `assets/styles/components/pagination.css`.
10. Tooltip: `<twig:Ds:Tooltip id="...">text</twig:Ds:Tooltip>`. The anchor's own rule shows it on hover and focus. The CSS is `assets/styles/components/tooltip.css`.
11. StatusBox: `<twig:Ds:StatusBox kind="stuck" :title="..." :chip="..." :lead="..." :fields="..." :others="...">`. Kinds are stuck, needs-you, working, waiting and chip. The CSS is `assets/styles/components/status-box.css`.
12. StateMark: `<twig:Ds:StateMark kind="stuck" :label="...">tooltip content</twig:Ds:StateMark>`. Kinds are stuck, needs-you, working, waiting and chip. Its tooltip is interactive, so it may hold a link, and the board drag ignores a press inside it. The CSS is `assets/styles/components/state-mark.css`.
13. FilterBar: `<twig:Ds:FilterBar :action="..." :search="{id, name, value, label, placeholder}" :clearHref="..." :clearLabel="..." :count="...">` with the selects of the first row as the body and a `secondary` block for hidden fields and wrapping selects. Other props are `formAttributes`, `countAttributes`, `countFrame` and `switch`. Write no `lp-filter-*` class in a template. `Ds:Select` and `Ds:Input` take `compact` outside a bar. The CSS is `assets/styles/components/filter-bar.css`.
14. Panel: `<twig:Ds:Panel as="section">`. It draws one bordered tinted card and has no variants. The CSS is `assets/styles/components/panel.css`.
15. SectionHeading: `<twig:Ds:SectionHeading :description="...">Title<twig:block name="actions">...</twig:block></twig:Ds:SectionHeading>`. The content is the title, and `level` is 2 or 3. `titleId` sets the heading id. The CSS is `assets/styles/components/section-heading.css`.
16. FormActions: `<twig:Ds:FormActions>` wraps the buttons that end a form or a dialog. `align="between"` pushes the first control left. The CSS is `assets/styles/components/form-actions.css`.
17. Menu: `<twig:Ds:Menu id="..." align="start" size="md">` with a `trigger` block and a `content` block. The content holds `Ds:MenuItem` (a link with `href`, a button without), `Ds:MenuHeading`, `Ds:MenuDivider` and `Ds:MenuGroup` (a run of rows, with `heading` and `moreLabel` props and a `more` block). The trigger carries `data-popover-target="trigger"`, `data-action="click->popover#toggle"`, `aria-expanded="false"` and `aria-controls`. Tones are light and chrome. The CSS is `assets/styles/components/menu.css`.
18. Form parts: `<twig:Ds:FormField :fieldView="form.title" />` draws the label, widget, hint and errors of a Symfony field, and `widgetKind="select"` or `widgetKind="textarea"` picks the widget classes. `Ds:Input`, `Ds:Select`, `Ds:Textarea`, `Ds:Label`, `Ds:Hint` and `Ds:FieldErrors` draw one part each. A Symfony widget in a custom layout takes `ds_input_class('select')` and `ds_label_class()`. The CSS is `assets/styles/components/form.css`.
19. Avatar: `<twig:Ds:Avatar :name="project.name" size="sm" shape="square" tone="danger" />`, or `icon="lucide:bot"` for an icon. Sizes are xs, sm, md and lg. Tones are accent, tint, ink, danger and chrome. `presence="live"` draws the bridge dot. The `ds_initial` filter gives the letter. The CSS is `assets/styles/components/avatar.css`.
20. CodeBlock: `<twig:Ds:CodeBlock label="..." :text="..." :copyLabel="..." :copiedLabel="..." testid="..." />`. The header holds the label and a copy button of the same text. `:copy="false"` draws no button. The `meta` block adds a marker to the header, and the `tail` block adds markup after the code, such as a cursor. Write no `lp-code-dark` class in a template. The CSS is `assets/styles/components/code-block.css`.
21. CopyButton: `<twig:Ds:CopyButton :text="..." :label="..." :copiedLabel="..." />`. It owns the `clipboard` controller, so write no `data-controller="clipboard"` in a page template. `variant="icon"` draws a link icon that turns into a check, and takes a button class through `class`. The text variant takes `buttonVariant` and `buttonSize`. The admin pages keep their own copy buttons. The CSS is `assets/styles/components/copy-button.css`.
22. ActionForm: `<twig:Ds:ActionForm action="{{ path('app_x') }}" token="x-action" frame="_top">` with the button as content. It renders the form, the hidden `_csrf_token` from `csrf_token(token)` and the passthrough attributes. Use it for a form that holds only a token and one button. A form with a second field or button stays a plain form. The CSS is `assets/styles/components/action-form.css`.
23. DataTable: `<twig:Ds:DataTable modifier="runs" :label="..." :columns="[{label: ..., align: 'end'}]">` with `lp-data-table__row` rows (`role="row"`) and `lp-data-table__cell` cells (`role="cell"`) in the content. A modifier class sets `--lp-data-table-columns`. A `header` block replaces the header cells, and `srOnly: true` on a column hides its label from sight. The CSS is `assets/styles/components/data-table.css`.
24. RelativeTime: `<twig:Ds:RelativeTime :datetime="card.updatedAt" />` draws "2h ago". `mode="date"` draws "5 Oct 2026" and `mode="datetime"` adds the hour. `:now` sets the moment that relative counts from. The `relative_time` filter stays for a sentence that holds the age. The CSS is `assets/styles/components/relative-time.css`.

25. Icon size: `<twig:ux:icon name="lucide:x" class="lp-icon lp-icon--sm" />`. It is a class scale and no component, so an icon pays no render cost. The sizes are xs (3), sm (3.5), md (4) and lg (5), plus xl (8) and 2xl (12) for the icon of an empty state, which `Ds:EmptyState` takes as `iconSize`. Write no `w-N h-N` or `size-N` on an icon, and spell the tag `ux:icon`. The CSS is `assets/styles/components/icon.css`.
26. PageHeader: `<twig:Ds:PageHeader :title="..." :description="..." variant="workshop" titleClass="lp-workspace-title--doc">` with the buttons in `<twig:block name="actions">`. The CSS is `assets/styles/components/page-header.css`.
27. SplitView: `<twig:Ds:SplitView class="lp-workspace" data-controller="...">` draws `lp-split-page`. Write `lp-split-region`, `lp-split-view` and `lp-split-pane` by hand inside it. The CSS is `assets/styles/components/split-view.css`.
28. ListRow: `<twig:Ds:ListRow :title="..." :current="..." href="..." icon:data-kind="...">` with `<twig:block name="icon">` and `<twig:block name="meta">`. Use `lp-list-row__flag` for a highlighted item in the meta line. The CSS is `assets/styles/components/list-row.css`.
29. Switch: `<twig:Ds:Switch name="managed" id="managed" :checked="true" label="..." help="..." />`. With no label and no help it draws the bare checkbox with `role="switch"`. With either, a field row wraps it and `aria-describedby` ties the help to the input. `:fieldView="form.managed"` draws a Symfony checkbox field. The CSS is `assets/styles/components/switch.css`.
30. Stepper: `<twig:Ds:Stepper :steps="[...]" :current="2" :complete="false" aria-label="..." />`. The steps are translated labels and `current` counts from 1. The list carries `data-wizard-step`, and the current step has `aria-current="step"`. The CSS is `assets/styles/components/stepper.css`.
31. SplitButton: `<twig:Ds:SplitButton id="..." :toggleLabel="..." menuSize="lg">` with a `main` block that holds one `Ds:Button` and a `content` block of `Ds:MenuItem` rows with `role="menuitem"`. It draws the chevron toggle and holds the `split-button` controller. With no main button the toggle is a whole button with its label. With no rows the main button stands alone. The CSS is `assets/styles/components/split-button.css`.
32. Chart: `<twig:Ds:Chart :layout="layout" :labels="labels" id="..." />`. The layout is a `LineLayout`, `BarLayout`, `DonutLayout` or `NumberLayout`, and its kind picks the type. `labels` holds the series names by position and is escaped. The component draws the plot, the legend, the hover cards and a `Ds:DataTable`, and holds the `chart-hover` controller. A number chart has no plot, table or hover card. The CSS is `assets/styles/components/chart.css`.
33. GraphTile: `<twig:Ds:GraphTile :name="..." :settings="..." state="ready">` with the chart as content. The `actions` block holds the menu, the `rename` block a form that replaces the name, and the `footer` block the buttons of a broken tile (`state="broken"` with `brokenTitle` and `brokenBody`). `legend` false hides the chart legend, and `grip` false hides the drag grip. The CSS is `assets/styles/components/graph-tile.css`.

A pattern check also covers two blocks that have no class of their own. `DesignSystemPatternCheck` in `gamache.php` fails on a hand-written `relative_time` filter call, which belongs in `<twig:Ds:RelativeTime>`. It also fails on a one-button form with a `_csrf_token` input and `method="post"`, which belongs in `<twig:Ds:ActionForm>`. It skips the admin template folders and the component's own template. Where a component cannot go, such as inside a `trans()` placeholder or a component prop, add `{# @design-system-ignore #}` on the same line, or on one of the lines of the form.

A child card that adds a component adds it to this list in the same branch.

#### Change a component

One component change touches these files:

1. The Twig template `templates/components/Ds/<Name>.html.twig`.
2. The stylesheet `assets/styles/components/<name>.css`.
3. The entry in `src/Module/DesignSystem/Catalog.php`, with its variants, sizes and states.
4. The specimen `templates/Module/DesignSystem/specimen/<name>.html.twig`.
5. The export wrapper under `templates/Module/DesignSystem/export/`, when the component has one.
6. The component test and the parity test under `tests/Module/DesignSystem/`.
7. The entry in the list above and the table in `docs/contributing/design-system.md`.

A part that no component covers stays page-level markup. Add no new component for it.

Other pages can carry a page rule that the change makes needless. Search `assets/styles/app.css` for such rules. Update only the pages of your branch. Then send one non-blocking inbox to-do (`loupe-inbox`) that proposes a card for the other pages and names each page. Write no card yourself.

The Design System artifact "Loupe Design System" is a copy of these files. `bin/console app:design-system:export` builds the files, and the `loupe-design-rebuild` skill publishes them to the artifact. A change to `tokens.css`, a component or the catalog needs the rebuild step of `working-with-prs` "The Design System artifact rebuild".

### Icons

Use the Symfony UX Icons bundle with Lucide for every icon, and never embed inline SVG. Prefer the Twig component form. `ux_icon()` is an acceptable alternative.

```twig
<twig:ux:icon name="lucide:x" class="lp-icon lp-icon--sm mt-px" />
{# or #}
{{ ux_icon('lucide:x', {'class': 'lp-icon lp-icon--sm mt-px'}) }}
```

`assets/icons/` is committed. A self-hosted instance must render its UI with no egress, so the SVGs ship in the repo and therefore in the production image. `iconify.on_demand` is off in prod, so a production instance never calls `api.iconify.design`. It stays on in dev and test, so a newly used icon renders immediately while you work, but that icon is then only in your local cache and the build would ship without it.

So run `bin/console ux:icons:lock` after you add a new icon, and commit what appears under `assets/icons/`. The command scans the project and imports what it finds. Do not `git rm --cached` these files. Earlier guidance said to, and it now breaks production rendering.

`ux:icons:lock` only sees icon names it can read as literals. A name built at runtime is invisible to the scan, for example `<twig:UX:Icon name="simple-icons:{{ provider }}" />` in `templates/Module/Account/security/_social_buttons.html.twig`. Import those by hand with `bin/console ux:icons:import simple-icons:google simple-icons:github`. Otherwise they are missing in prod with no error, because `ignore_not_found: true` renders nothing. When you add a dynamically-named icon, import its full set of possible values explicitly.

Imported Lucide SVGs use `stroke="currentColor"`. Control the stroke colour with a text colour class on the icon or its parent, and never hardcode `stroke="white"` as an attribute.
