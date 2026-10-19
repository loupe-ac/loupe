Loupe's sidebar link. One link of the sidebar: an icon, a label, an optional count and the current-page mark.

```jsx
<NavItem label="Board" icon={<BoardIcon />} active />
<NavItem label="Documents" icon={<FileIcon />} count={12} />
```

Put the links inside the sidebar, which gives them the dark chrome background. Pass `active` on the link of the current page. The component sets `aria-current="page"` itself. Pass an svg that uses `currentColor` as the icon. The component emits the real `lp-sidebar__link` classes of the app.
