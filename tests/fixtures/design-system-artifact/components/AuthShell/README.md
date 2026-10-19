Loupe's sign-in page frame. The frame of every sign-in, sign-up and install page: a topbar with the brand mark, one centred card and a footer of legal links.

```jsx
<AuthShell>
  <h1>Sign in</h1>
  <Input placeholder="Email" />
  <Button variant="primary" size="lg" className="lp-auth__action">Sign in</Button>
</AuthShell>
```

Put the form fields inside as `FormField`, `Input` and `Button`, and give the submit button the class `lp-auth__action` so it fills the card. The frame draws the brand mark and the app footer itself. Pass `footer` to replace the footer. The component emits the real `lp-auth` classes of the app.
