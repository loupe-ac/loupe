Loupe's master-detail page. A master-detail page: a held header over a list pane beside a detail pane, each scrolling on its own.

```jsx
<SplitView
  header={<PageHeader title="Needs you" />}
  list={<><ListRow title="Which format?" /><ListRow title="Review the plan" current /></>}
  detail={<p>The ask you picked.</p>}
/>
```

Give the root a height, because the panes scroll inside it. Below 42.5rem the panes stack and the page scrolls. The component draws the real `lp-split-region`, `lp-split-view` and `lp-split-pane` classes of the app.

Put a `lp-split-page` page inside the main column of the AppShell. Use `header` for the PageHeader, and keep it to one row.
