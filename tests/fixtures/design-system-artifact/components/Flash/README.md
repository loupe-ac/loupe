Loupe's flash message. A short message about the result of an action.

```jsx
<Flash severity="success">Document saved</Flash>
<Flash severity="error" dismissLabel="Dismiss">Upload failed</Flash>
```

Pass `severity` as `success`, `error`, `warning` or `info`. Pass `dismissLabel` to draw a dismiss button. Messages are sentence case and never end in a full stop. The component emits the real `lp-flash` classes of the app.
