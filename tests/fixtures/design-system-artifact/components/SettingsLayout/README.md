Loupe's settings layout. A settings page: the page header, a section navigation beside the content, and the content column.

```jsx
<SettingsLayout
  title="Project settings"
  description="Rename the project, or change how it works."
  navLabel="Project settings"
  items={[
    { label: 'General', href: '#', active: true },
    { label: 'Columns', href: '#' },
  ]}
>
  <Panel as="section">
    <SectionHeading>General</SectionHeading>
  </Panel>
</SettingsLayout>
```

Pass one item for each section and mark the open one `active`. Put each panel of the open section in a `Panel` inside the content. Pass `columns` for a page whose content needs its own column width.

Titles are sentence case and never end in a full stop. The component emits the real `lp-settings` classes of the app.
