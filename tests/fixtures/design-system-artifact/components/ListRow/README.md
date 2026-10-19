Loupe's list row. One row of a list: an icon, a title and a meta line. The current row is highlighted.

```jsx
<ListRow href="#" title="Which format?" meta="Loupe, 5 minutes ago" />
<ListRow href="#" title="Review the plan" meta={<><span>Loupe</span><span className="lp-list-row__flag">Blocking</span></>} current />
```

Pass `current` for the row that is open. Wrap a `lp-list-row__flag` span in `meta` to highlight one item of the meta line, such as a blocking mark. Rows sit in the list pane of a SplitView.

Titles are sentence case and never end in a full stop. The component emits the real `lp-list-row` classes of the app.
