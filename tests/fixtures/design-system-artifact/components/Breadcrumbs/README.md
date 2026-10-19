Loupe's breadcrumbs. The trail at the top of a page: links to the parent pages and the current page last.

```jsx
<Breadcrumbs items={[{ label: 'Documents', href: '#' }, { label: 'Tech design' }]} />
```

List the parent pages first and the current page last. The last item is the page you are on, so it takes no link. The trail never starts with the project name, because the sidebar already shows it. Below the large breakpoint the trail shows the current page alone. The component emits the real `lp-crumbs` classes of the app.
