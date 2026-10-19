Loupe's state mark. An icon that names what a card needs now, with its reason in an interactive tooltip.

```jsx
<StateMark kind="stuck" label="Stuck">A pull request waits for a merge.</StateMark>
<StateMark kind="needs-you" label="Needs you">Read the <a href="#">review rules</a>.</StateMark>
```

Pass `kind` as one of stuck, needs-you, working, waiting, chip, and a `label` that names the state. The content is the tooltip, and it may hold a link. The tooltip stays open while the pointer or the focus is inside it. The component emits the real `lp-state-mark` classes of the app.
