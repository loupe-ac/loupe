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
2. `components/<name>.css` holds the styles of one building block. A child card adds each file with its component.
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
| Tabs | A strip of links or tab buttons with an underline | none |
| Pagination | The previous, next and page-number control of a list | none |
| Tooltip | A text bubble under its anchor | interactive, which stays open for a link |
| BrandMark | The logo square and the brand name. Sizes sm and md. `:markOnly="true"` draws the square alone | none |
| AuthShell | The frame of the sign-in, sign-up and install pages. It has no Twig component: `@Account/auth_base.html.twig` draws it with the `lp-auth` classes, and a page fills the `auth_body` block. `lp-auth__action` makes a submit button fill the card | none |

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

<twig:Ds:Tooltip id="why-1">The reason.</twig:Ds:Tooltip>
```

The dialog sets `data-modal-target="dialog"` itself. The tabs take `tag="div"` for a tab list that is not a navigation. The pagination draws nothing when there is one page. The feature that owns the anchor decides when a tooltip shows. A tooltip that holds a link takes `:interactive="true"`, and its anchor needs the `interactive-tooltip` controller, so use a StateMark for it.

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

## The marketing shell

A public page for a signed-out visitor extends `templates/marketing_base.html.twig`. The base draws the header, the page body and the footer. A page fills `block body`, or swaps `marketing_header`, `marketing_main` and `marketing_footer` when it draws its own, as the home page does. A signed-in visitor gets the app shell instead.

The shell is a base template with page blocks, so it has no Twig component. Its catalog entry has no template, and the `lp-landing` classes are documented here.

Every public footer takes the privacy, terms and AI policy links from `templates/_legal_links.html.twig`:

```twig
{% include '_legal_links.html.twig' with {class: 'lp-landing-footer__link'} only %}
{% include '_legal_links.html.twig' with {class: 'lp-home-footer__link', listItem: true} only %}
```

## The sign-in shell

Every sign-in, sign-up, password reset, install and consent page extends `@Account/auth_base.html.twig`. The base draws the topbar with the brand mark, one centred card and the footer, through the classes of `assets/styles/components/auth-shell.css`. The footer lives in `@Account/_auth_footer.html.twig`, which the styleguide specimen also draws. A page fills the blocks `title`, `topbar_nav` and `auth_body`. A page that needs a wider card sets `{% block auth_shell_class 'lp-auth__card lp-auth__card--wide' %}`.

The forms in the card use the app form parts, `Ds:FormField`, `Ds:Input`, `Ds:Label` and `Ds:Button`, like any other form. Give the submit button `class="lp-auth__action"` so it fills the card.

## The email layout

Every email extends `templates/email/_layout.html.twig`. The layout holds the stylesheet inlining, the doctype, the brand, the card and the footer. An email fills three blocks: `title`, `content` and `footer`.

```twig
{% extends 'email/_layout.html.twig' %}

{% block title %}{{ 'x.email.title'|trans }}{% endblock %}

{% block content %}
    <h1>{{ 'x.email.heading'|trans }}</h1>
    <a href="{{ url }}" class="btn">{{ 'x.email.cta'|trans }}</a>
{% endblock %}

{% block footer %}
    {{ 'x.email.footer'|trans }}
{% endblock %}
```

An email has no catalog entry or styleguide specimen. It gets its styles from `email.css` and holds its own copy of the brand mark, because no app stylesheet reaches an email client.

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

The command does not copy icons. A catalog entry that needs icons must add them to the command.

## The rules

1. Write no arbitrary value. Use a token or a named Tailwind scale step. `NoArbitraryValuesCheck` enforces this in `just gamache`.
2. Snap an off-scale value to the nearest token. A change to a token is a change to the system.
3. Use a building block from the catalog. Write no raw markup for a block that the catalog lists.

Read `project-frontend` for the full conventions of CSS, Stimulus and Turbo.

## Related pages

- [Development](development.md)
- [Architectural priorities](architectural-priorities.md)

`just gamache` blocks a template that writes `lp-btn`, `lp-flash`, `lp-empty-state`, `lp-badge`, `lp-tag`, `lp-status-chip`, `lp-dialog`, `lp-tabs`, `lp-pagination`, `lp-tooltip` or `lp-brand` by hand.
