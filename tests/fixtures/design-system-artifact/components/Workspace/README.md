Loupe's page container. The page container: the frame that holds the content of a page, with a named width.

```jsx
<Workspace><PageHeader title="Documents" /></Workspace>
<Workspace width="narrow"><PageHeader title="Rename document" /></Workspace>
<Workspace width="full">{/* a board or a split view */}</Workspace>
```

Widths: `default`, `narrow`, `full`. Put the container inside the main column of the AppShell, and the PageHeader first inside it. The component emits the real `lp-workspace` classes of the app.
