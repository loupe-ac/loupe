Loupe's status chip. A chip that names a state, with an optional reason in a tooltip.

```jsx
<StatusChip modifier="ok">Approved</StatusChip>
<StatusChip modifier="failed" label="Stuck for 2 h" reason="A pull request waits for a merge." />
<StatusChip modifier="neutral" dot={false}>Draft</StatusChip>
```

Pick the colour with `modifier`: pending, addressed, resolved, ok, failed, neutral. Pass `reason` to add a help icon and a tooltip that opens on hover and focus. The text is sentence case and never ends in a full stop. The component emits the real `lp-status-chip` classes of the app.
