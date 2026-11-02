Loupe's tooltip. A short label that shows over an element on hover or focus. A touch screen hides it. The interactive variant stays open for a link.

```jsx
<Tooltip>Save the document</Tooltip>
<Tooltip interactive>Read the <a href="#">review rules</a></Tooltip>
```

Pass `interactive` for a tooltip that holds a link. Text is sentence case and never ends in a full stop. The component emits the real `lp-tooltip` classes of the app.
