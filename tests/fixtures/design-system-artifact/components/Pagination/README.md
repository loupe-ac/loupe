Loupe's pagination. The previous, next and page-number links of a long list.

```jsx
<Pagination page={2} totalPages={7} pageList={[1, 2, 3, null, 7]} hrefFor={(page) => '/documents?page=' + page} />
```

Pass `pageList` with `null` for a gap. The labels are English: "Page 2 of 7", "Previous page" and "Next page". The component draws nothing when `totalPages` is 1 or less. It emits the real `lp-pagination` classes of the app.
