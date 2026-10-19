Loupe's marketing shell. The frame of a public page for a signed-out visitor: the marketing header, the page body and the footer with the legal links.

```jsx
<MarketingShell>
  <article>The page body.</article>
</MarketingShell>
```

The shell draws the header with the brand, the sign-in link and the sign-up button, and the footer with the brand, the copyright, the source and docs links, the privacy, terms and AI policy links, and the X and Bluesky links. The app draws the source and social links with icons, and the export names them in text. The children fill the page between them. The component emits the real `lp-landing` classes of the app.
