Loupe's status box. The state of a card with its reason, its facts, what clears it, and the other states that apply.

```jsx
<StatusBox
  kind="stuck"
  title="Status"
  chip="Stuck for 2 h"
  lead="A pull request waits for a merge."
  fields={[{ label: 'Since', value: '14:02, 2 hours ago' }, { label: 'To unstick it', value: 'Merge the pull request.', wide: true }]}
  othersTitle="Also true"
  others={[{ kind: 'waiting', label: 'Waiting', lead: 'A review is open.', since: '1 h' }]}
/>
```

Pass `kind` as one of stuck, needs-you, working, waiting, chip. The chip, the lead and the facts are translated text. Leave out `fields` and `others` for a box with no facts or no other states. The component emits the real `lp-status-box` classes of the app.
