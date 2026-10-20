Loupe's button. Any action or link that looks like a button.

```jsx
<Button variant="primary">Save</Button>
<Button variant="ghost" href="/back">Back</Button>
<Button size="sm" disabled>Disabled</Button>
```

Variants: `primary`, `inverse`, `outline`, `success`, `danger`, `ghost`, `danger-ghost`, `on-card`, `icon`, `compact`, `open`. Sizes: `sm`, `lg`. States: hover, active, disabled.

Labels are sentence case and never end in a full stop. Combine modifiers with a space, such as `variant="ghost icon"`. The component emits the real `lp-btn` classes of the app.
