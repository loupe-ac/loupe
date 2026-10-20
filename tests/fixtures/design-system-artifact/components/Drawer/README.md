Loupe's drawer. A panel that slides in from the right edge, with a header, a body that scrolls and a footer.

```jsx
<Drawer open
  header={<><h2 className="lp-drawer__title">Recent activity</h2><Button variant="icon" aria-label="Close">×</Button></>}
  footer={<Button>Open the full list</Button>}
>
  <p>The body.</p>
</Drawer>
```

Pass `open` to show it. The app opens the real drawer with the `modal` controller, which slides it in from the right edge. Give the header and the footer only when the drawer needs them. The component draws the real `lp-drawer` classes of the app.
