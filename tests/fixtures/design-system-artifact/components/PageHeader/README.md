Loupe's page header. The heading row of a page: a title, an optional description and the actions beside them.

```jsx
<PageHeader title="Documents" description="Every document of this project." />
<PageHeader
  title="Documents"
  description="Every document of this project."
  actions={<div><Button>Filter</Button><Button variant="primary">New document</Button></div>}
/>
<PageHeader variant="workshop" title="Workshop" />
```

Variants: `workshop`. Pass the buttons as `actions`, and they draw after the heading. Use `titleClass` for an extra class on the h1.

Titles are sentence case and never end in a full stop. The component emits the real `lp-page-header` classes of the app.
