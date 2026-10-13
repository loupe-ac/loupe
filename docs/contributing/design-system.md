---
title: Design system
description: The tokens, the styleguide and the rules for the markup of the Loupe app.
---

The design system is the set of values and building blocks that give every page of the app one look. The tokens are values. The components are building blocks made of those values.

## The styleguide

Run the app in dev and open `/styleguide`. The page draws every token group and every catalog entry from the real stylesheet. A component appears there when its child card adds it.

The route exists in dev only. In production it does not exist.

## The tokens

All tokens live in `assets/styles/tokens.css`. `assets/styles/app.css` imports it. A token is a CSS custom property, and the comment above it is its use. A `@group` comment starts a group.

| Group | What it holds |
|---|---|
| colour | The raw colour values, and the Tailwind names for them |
| type | The font stacks. The Tailwind text sizes carry the scale |
| spacing | One step of the spacing scale. Every gap and size is a multiple of it |
| radius | The corner radii |
| shadow | The shadows of surfaces that float above the page |
| motion | The durations and the easing curves |

A new token appears on the styleguide with no other change.

## The stylesheets

1. `tokens.css` holds the tokens and nothing else.
2. `components/<name>.css` holds the styles of one building block. A child card adds each file with its component. `components/shell.css` holds the app shell, which has no Twig component.
3. `app.css` imports both, and holds the rules of the features.

The widget and the email stylesheet cannot import `tokens.css`. They hold copies of the values they need, and a test keeps each copy equal to the token.
In `email.css`, each copied value names its token in a trailing comment, such as `/* --accent */`, and `EmailTokensMatchAppTest` compares the two.

## The components

A building block is a Twig component under `templates/components/Ds/`. Its CSS lives in `assets/styles/components/<name>.css`, and its entry in `src/Module/DesignSystem/Catalog.php` lists its root class, variants and states.

| Component | Use | Variants |
|---|---|---|
| Button | Any action or link that looks like a button | primary, inverse, outline, success, danger, ghost, danger-ghost, icon, compact, open, on-card. Sizes sm and lg |
| Input | A one-line text field | mono |
| Select | A native select | none |
| Textarea | A multi-line text field | none |
| Label | The text that names a field. It can be a label, legend, p, span or div | none |
| FormField | One field of a form: its label, widget, hint and errors, or a wrapper for your own body | none |
| FieldErrors | The list of errors of one field | none |
| Hint | A line of help under a field | none |
| Flash | A message after an action, with a dismiss button | success, error, warning, info |
| EmptyState | A panel that says a list or page has nothing yet | none. Takes an icon, a title, a body and one link |
| Badge | The status of a document in a list | in-review, draft, approved, changes-requested |
| Tag | A short label, such as a card type or a column | neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange |
| StatusChip | A state with a coloured dot and an optional reason tooltip | pending, addressed, resolved, ok, failed, neutral |
| StateMark | An icon that names what a card needs now, with its reason in an interactive tooltip | stuck, needs-you, working, waiting |
| StatusBox | The state of a card with its reason, its facts, what clears it, and the other states that apply | stuck, needs-you, working, waiting |
| Dialog | A modal that the `modal` Stimulus controller opens | document, search |
| Tabs | A strip of links or tab buttons with an underline, or a pill group that switches a view or a setting | segmented |
| Pagination | The previous, next and page-number control of a list | none |
| FilterBar | The search, selects, switch, clear link and count above a filtered list | none |
| Tooltip | A text bubble under its anchor | interactive, which stays open for a link |
| Panel | A bordered tinted card that holds one block of a page | none. Takes `as` for the element |
| SectionHeading | The title of a section, with an optional sentence and optional actions | none. The content is the title. Takes `level`, `description`, `titleId` and an `actions` block, and no `title` prop |
| FormActions | The row of buttons that ends a form or a dialog | none. Takes `align` |
| CodeBlock | A dark block of code or a command, with a header label and a copy button | none |
| CopyButton | A button that copies a text and shows that it did. It owns the `clipboard` controller | text, icon. Tones light, chrome |
| Avatar | The first letter of a name, or an icon, in a disc or a rounded square | tones accent, tint, ink, danger, chrome. Sizes xs, sm, md, lg. Takes `shape` and `presence` |
| RelativeTime | A moment as a short age such as 2h ago, or as a date, in a `time` tag with the full timestamp as its title | modes relative, date, datetime |
| Icon | The size of a `ux:icon` tag. A class scale, with no component to render | xs (3), sm (3.5), md (4), lg (5), and xl (8) and 2xl (12) for an empty-state icon. Write `class="lp-icon lp-icon--sm"` |
| DataTable | A list of one-line rows under a header | No variants. A `modifier` sets the column grid, `label` names the table. A `header` block replaces the header cells |
| Menu | A panel of links or actions that opens under a trigger, with headings and dividers | light, chrome |
| ActionForm | A form that posts with a CSRF token and holds one button. Takes `action`, `token` (the registered CSRF id) and `frame` | none |
| PageHeader | The heading row of a page: the title, an optional description and a block of actions | workshop, which gives the title the workshop size |
| SplitView | A master-detail page: a held header over a list pane beside a detail pane | none |
| ListRow | One row of a list: an icon, a title and a meta line | none. The state current marks the open row |

The PageHeader takes `title`, `description`, `variant` and `titleClass`, and a named `actions` block that draws after the title block. Its CSS is `assets/styles/components/page-header.css`. It also holds the `lp-workspace-title`, `lp-workshop-title` and `lp-workspace-desc` rules.

```twig
<twig:Ds:PageHeader :title="'documents.title'|trans" :description="'documents.description'|trans">
    <twig:block name="actions"><twig:Ds:Button variant="primary">New</twig:Ds:Button></twig:block>
</twig:Ds:PageHeader>
```

The SplitView draws the page root, `lp-split-page`, and takes any attribute, such as a Stimulus `data-controller`. The page writes the `lp-split-region`, `lp-split-view` and `lp-split-pane` classes inside it by hand, because each page puts its own controller on the region. A page with a header writes the header first, then the region. Its CSS is `assets/styles/components/split-view.css`.

```twig
<twig:Ds:SplitView class="lp-workspace">
    <div class="lp-split-region">
        <div class="lp-split-view">
            <div class="lp-split-pane">{# the list #}</div>
            <div class="lp-split-pane">{# the detail #}</div>
        </div>
    </div>
</twig:Ds:SplitView>
```

The ListRow takes `title`, `tag` and `current`, and the named blocks `icon` and `meta`. Other attributes go to the row. An attribute with an `icon:` prefix goes to the icon span. The `lp-list-row__flag` class marks one item of the meta line, such as a blocking mark. Its CSS is `assets/styles/components/list-row.css`.

```twig
<twig:Ds:ListRow :title="row.title" :current="true" href="{{ path('app_home') }}" icon:data-kind="question">
    <twig:block name="icon"><twig:ux:icon name="lucide:circle-help" /></twig:block>
    <twig:block name="meta"><span>Loupe</span></twig:block>
</twig:Ds:ListRow>
```

Write a button like this:

```twig
<twig:Ds:Button variant="primary" size="sm" type="submit">Save</twig:Ds:Button>
<twig:Ds:Button variant="ghost" href="{{ path('app_home') }}">Back</twig:Ds:Button>
```

The component passes every other attribute to the element and appends your `class`. An `href` renders a link. A Symfony form button draws its own tag, so give it the classes with `ds_button_class('primary')`:

```twig
{{ form_widget(form.save, {attr: {class: ds_button_class('primary')}}) }}
```

Write the feedback parts like this:

```twig
<twig:Ds:Flash severity="error" :dismissLabel="'flash.dismiss'|trans">{{ message }}</twig:Ds:Flash>
<twig:Ds:EmptyState icon="lucide:inbox" :title="'x.empty'|trans" :body="'x.empty.body'|trans" />
<twig:Ds:Badge :status="document.status.value">{{ label }}</twig:Ds:Badge>
<twig:Ds:Tag tone="amber">{{ label }}</twig:Ds:Tag>
<twig:Ds:StatusChip modifier="ok" :label="'x.state'|trans" :reason="reason" />
```

A Tag takes `as="li"` inside a list. A StatusChip takes `:dot="false"` for no dot, and `as="button"` for a chip that toggles a panel.

Write a dialog, tabs, pagination and a tooltip like this:

```twig
<twig:Ds:Dialog size="document" aria-labelledby="edit-title" data-action="cancel->modal#close">
    <h3 id="edit-title" class="lp-dialog-title">Edit</h3>
</twig:Ds:Dialog>

<twig:Ds:Tabs class="lp-analytics-tabs" aria-label="Sections">
    <a class="lp-tabs__tab" href="{{ path('app_home') }}" aria-current="page">Home</a>
</twig:Ds:Tabs>

<twig:Ds:Pagination route="app_projects" :page="page" :totalPages="totalPages" :pageList="pageList" />

<twig:Ds:FilterBar :action="path('app_projects')" :search="{id: 'q', name: 'q', value: query, label: 'Search', placeholder: 'Search'}" clearHref="{{ path('app_projects') }}" clearLabel="Clear" count="3 projects" />

<twig:Ds:CodeBlock label="Terminal" text="npm install" copyLabel="Copy" copiedLabel="Copied!" />

<twig:Ds:Tooltip id="why-1">The reason.</twig:Ds:Tooltip>
```

The dialog sets `data-modal-target="dialog"` itself. The tabs take `tag="div"` for a tab list that is not a navigation, and `variant="segmented"` for a pill group. A segmented switch that changes a setting uses buttons with `aria-pressed`. The pagination draws nothing when there is one page. The filter bar draws a GET form with the `autosearch` controller when you pass `action`. Its body holds the selects of the first row, and a `secondary` block holds hidden fields and selects that wrap. A select inside the bar takes the compact look from the bar. Outside a bar, `Ds:Select` and `Ds:Input` take `compact`. The code block draws a header label, the text, and a copy button of the same text. Pass `:copy="false"` for a block with no button. A copy button outside a block takes `variant="icon"` for an icon-only button. The admin pages keep their own copy buttons. The feature that owns the anchor decides when a tooltip shows. A tooltip that holds a link takes `:interactive="true"`, and its anchor needs the `interactive-tooltip` controller, so use a StateMark for it.

Write a menu like this:

```twig
<twig:Ds:Menu id="row-menu" align="start" size="md">
    <twig:block name="trigger">
        <twig:Ds:Button type="button" variant="ghost" aria-expanded="false" aria-controls="row-menu"
                data-popover-target="trigger" data-action="click->popover#toggle">More</twig:Ds:Button>
    </twig:block>
    <twig:block name="content">
        <twig:Ds:MenuHeading>Open in</twig:Ds:MenuHeading>
        <twig:Ds:MenuItem :href="path('app_home')" :current="true">Home</twig:Ds:MenuItem>
        <twig:Ds:MenuDivider />
        <twig:Ds:MenuItem type="submit" form="row-form">Delete</twig:Ds:MenuItem>
    </twig:block>
</twig:Ds:Menu>
```

The menu holds the `popover` controller. Its trigger carries the three `data-popover-target`, `data-action` and `aria-*` attributes, because the trigger markup is the caller's. The props are `tone` (light or chrome), `align` (end, start or stretch), `direction` (down or up), `size` (sm, md or lg), `density` (compact, flush or roomy) and `as`, the element of the panel. A form panel takes its `method` and `action` in `:panel`. A MenuItem is a link with `href` and a button without it. The mobile review menu and the review filter stay outside the component, because one is a drill-down sheet and the other is a `<details>` element.

Write the state of a card like this:

```twig
<twig:Ds:StateMark kind="stuck" :label="'board.card_state.stuck'|trans">{{ reason }}</twig:Ds:StateMark>
<twig:Ds:StatusBox kind="stuck" :title="'board.card_state.status'|trans" :chip="chip" :lead="lead" :fields="fields" :others="others" :othersTitle="'board.card_state.others'|trans" />
```

`kind` is `stuck`, `needs-you`, `working` or `waiting` for both blocks. The content of a StateMark is its tooltip. The chip of a StatusBox holds the state and how long it has held. Each entry of `fields` has a `label` and a `value`, and `wide: true` gives a sentence the full width. A StatusBox takes `fields`, `others` and `othersTitle` as optional props, and draws nothing for a prop that is empty.

### The form parts

`FormField` draws a Symfony form field with its label, widget, hint and errors. Pass `kind` for a select or a textarea, and `attr` for the attributes of the widget:

```twig
<twig:Ds:FormField :fieldView="form.title" :widgetAttr="{placeholder: 'Title'}" hintText="Shown on the board" />
<twig:Ds:FormField :fieldView="form.color" widgetKind="select" />
```

Give `FormField` no `field` and it wraps your own body. `as="fieldset"` draws a fieldset.

A plain field uses `Input`, `Select` or `Textarea`. A Symfony widget in a custom layout takes the classes from functions, because it draws its own tag:

```twig
{{ form_label(form.name, null, {label_attr: {class: ds_label_class()}}) }}
{{ form_widget(form.kind, {attr: {class: ds_input_class('select')}}) }}
<twig:Ds:FieldErrors :fieldView="form.kind" data-field-errors="kind" />
```

`just gamache` blocks a template that writes `lp-input`, `lp-select`, `lp-textarea`, `lp-label`, `lp-form-field`, `lp-field-errors` or `lp-form-hint` by hand.

### The page parts

A page part is the frame of a page. It sits in the `layout` group of the catalog. The catalog entry names its root class, and its export wrapper draws the inner structure.

| Part | Use | Root class |
|---|---|---|
| AppShell | The frame of every signed-in page: the sidebar, the top bar and the main column | `lp-app` |

`templates/base.html.twig` draws the app shell. It stays there, because the sidebar reads the current user, the current project, the feature flags and the routes. The CSS lives in `assets/styles/components/shell.css`, which `app.css` and `design-system.css` both import.

The export wrapper in `templates/Module/DesignSystem/export/layout/AppShell/` is a second copy of that markup. It holds the nav links with their English labels and draws the icons as inline SVG. `ExportDesignSystemHandlerTest` keeps the copy in step: every shell class and every `nav.link.*` key of `base.html.twig` must appear in the wrapper. The wrapper must also hide each part that `base.html.twig` shows only inside `{% if project %}`: a sidebar part behind `hasProject`, and a top bar part behind `onProjectPage`. It must hide each link that `base.html.twig` gates with `is_feature_enabled()` or `is_granted('ROLE_ADMIN')` too. Change both files when you change the shell markup.

The wrapper takes these props: `projectName`, `projectPage`, `active` (the name of the current nav link), `crumbs`, `meta`, `actions`, `userName`, `counts`, `projects`, `features` and `admin`. The app reads two projects. The sidebar shows `sidebar_project()`, which is the last project visited on a page outside a project. The top bar reads `current_project()`, which is null there. `projectName` is the sidebar project, and `projectPage={false}` hides New work on a page outside a project. Pass `projectName={null}` when there is no sidebar project. The wrapper then also hides the project switcher, the project nav and Project settings. `features` names the flags that are on, such as `{ inbox: true }`, and each flag is off by default. `admin` shows the Admin link. `projects` lists the projects of the switcher as `{ name, documentCount }` entries, and the entry named `projectName` gets the current marker. The page goes in as the children:

```jsx
<AppShell projectName="Acme" active="board" crumbs={['Acme', 'Board']} userName="riley@example.com">
  <h1>Board</h1>
</AppShell>
```

These are the documented shell classes.

| Class | What it is |
|---|---|
| `lp-app` | The root. A flex row that fills the window, with the dark chrome colour |
| `lp-sidebar` | The left rail. `lp-sidebar--drawer` makes it a slide-in drawer below the `lg` breakpoint, and `lp-sidebar--open` opens it |
| `lp-sidebar__close` | The button that closes the drawer |
| `lp-sidebar__brand` and `lp-sidebar__brand-mark` | The logo lockup |
| `lp-sidebar__switcher` and its `__avatar`, `__text`, `__label`, `__name`, `__caret` parts | The button that shows the current project |
| `lp-switcher` | The wrapper of the switcher button and its panel |
| `lp-switcher__panel` | The list of projects that drops under the button |
| `lp-switcher__item` and its `__avatar`, `__text`, `__name`, `__count`, `__check` parts | One project in the panel |
| `lp-switcher__all` and `lp-switcher__all-glyph` | The link to all projects |
| `lp-sidebar__nav` | A group of links. `lp-sidebar__nav--secondary` is the group at the bottom |
| `lp-sidebar__nav-label` | The heading of a group. `--spaced` adds space above it |
| `lp-sidebar__link` | One nav link. `--active` marks the current page, and `__label` holds its text |
| `lp-sidebar__pill` | A count at the end of a link. `--amber` is the colour for something that waits on you |
| `lp-sidebar__spacer` | Pushes the bottom group down |
| `lp-sidebar__account` and its `__avatar`, `__text`, `__name`, `__logout-form`, `__logout` parts | The signed-in user and the log out button |
| `lp-scrim` | Dims the page behind an open drawer |
| `lp-shell` | The column to the right of the sidebar. It holds the top bar and the main column |
| `lp-topbar` | The bar above the page |
| `lp-topbar__menu` | The button that opens the drawer |
| `lp-topbar__lead`, `__trail`, `__crumb`, `__sep`, `__here` and `__meta` | The breadcrumb and the short line after it |
| `lp-topbar__global-actions` | The tools at the right end of the bar |
| `lp-topbar__new-work` | The New work button. It is a `Ds:Button` with this class added |
| `lp-main` | The main column. It is the only scroll container of the page |

## The Claude Design copy

A Claude Design project holds a copy of the design system. The app repository is the source of the copy.

Run this command to write the copy into a directory:

```bash
bin/console app:design-system:export <dir>
```

The command prints each path it wrote, relative to the directory, one per line.

| Path | What it holds |
|---|---|
| `tokens/*.css` | The tokens, split by group from `tokens.css`, and the fonts and base resets |
| `styles.css` | The Tailwind build of `assets/styles/design-system.css`, with the real `lp-` rules |
| `components/<group>/` | A React wrapper, a type file and a prompt file for each catalog entry, and one card for the group |
| `guidelines/` | One card for each token group |
| `readme.md` and `SKILL.md` | This page, and the skill that points at it |
| `assets/fonts/` | The font files and their licence |

The wrappers write the same class names as the Twig components. A wrapper adds no style of its own.

A part with an inner structure, such as a sidebar, sets `export` on its catalog entry and goes in the `layout` group. The value names a folder under `templates/Module/DesignSystem/export/layout/`. The folder holds the hand-written `component.jsx.twig`, `component.d.ts.twig`, `component.prompt.md.twig` and `demo.jsx.twig` of the part. The export renders those files in place of the generic templates. `demo.jsx.twig` draws the part in the `layout` group card. An entry with no Twig component sets `template` to null.

The command never writes `_ds_bundle.js`, `_ds_manifest.json`, `_adherence.oxlintrc.json`, `.thumbnail` or `ds-mount.js`. The platform makes those files.

The command does not copy icons. A wrapper that needs icons draws them as inline SVG, as the AppShell wrapper does.

## The rules

1. Write no arbitrary value. Use a token or a named Tailwind scale step. `NoArbitraryValuesCheck` enforces this in `just gamache`.
2. Snap an off-scale value to the nearest token. A change to a token is a change to the system.
3. Use a building block from the catalog. Write no raw markup for a block that the catalog lists.

Read `project-frontend` for the full conventions of CSS, Stimulus and Turbo.

## Related pages

- [Development](development.md)
- [Architectural priorities](architectural-priorities.md)

`just gamache` blocks a template that writes `lp-btn`, `lp-flash`, `lp-empty-state`, `lp-badge`, `lp-tag`, `lp-status-chip`, `lp-dialog`, `lp-tabs`, `lp-pagination`, `lp-list-filters`, `lp-code-dark`, `lp-copy-button`, `lp-tooltip` or `lp-menu` by hand. It also blocks the `lp-filter-*` classes the filter bar replaced.
