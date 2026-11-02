# Accepted: a site-review sign-in reaches the comments and the board

The credential is no longer a token in page source. The embed names a project with `data-project`, and the reviewer signs in through an OAuth pop-up. The grant carries the `site-review` scope and lives in the session storage of the tab. `PrepareWidgetAuthorizationHandler` runs two checks before the consent page shows. The page's origin must be on the project's allowed sites, and only the owner of the project may sign in.

That limits the holder to a person the project allows. What the scope reaches did not change, so this record stands. Read it as what a holder of the scope can do. Expect it to matter more when sign-in widens past the owner.

## The board half

A holder of the `site-review` scope lists every open card in its project and creates new ones. `GET /api/v1/live-review-widget/cards` returns the number, title and status of the open cards of the project, and `POST /api/v1/live-review-widget/cards` files a new one. Card titles are planning content. `POST /api/v1/live-review-widget/feedback` saves a comment on an open card of the project, or on a new card it creates. `DELETE /api/v1/live-review-widget/feedback/{id}` deletes a pending comment. It also deletes the card that the comment created, while that card is still in Backlog, holds no other feedback and is not an epic.

This was decided, not inherited. The Loupe document 'Picking and creating cards from the site-review widget' records the choice, the alternatives and the cost of each. It also notes that the choice reverses the reasoning behind `/sites` refusing the widget's scope. Read it before you reopen this.

### What bounds the board half

- Creation takes no `status` and no `pullRequestUrls`, so a caller cannot file into a column or attach a URL of their choosing.
- A card records `CardReporter::Reviewer`, which says the app could not name who raised it.
- The write joins the `site_review_write` limiter through `WidgetApiPaths`, so the board is not an unbounded spam target.
- Nothing bounds the read beyond the twenty-card page.

## The comment half

### What is accepted

Any holder of the `site-review` scope can read, edit, resolve and delete every **pending** comment in the project of that grant, whoever wrote it. `GET /api/v1/live-review-widget/review` returns bodies, URLs, selectors and quoted page text for the whole project, not only the current page. `PATCH /api/v1/live-review-widget/comments/{id}`, `DELETE /api/v1/live-review-widget/feedback/{id}` and `POST /api/v1/live-review-widget/comments/{id}/resolve` accept any pending id.

Resolve joined the list after delete, and it widens nothing in kind. A holder who can delete a comment outright can already do worse than sign it off. Resolve is also the only one of the four that a person can undo, from the Feedback tab of the comment's card. The acceptance covers the staging-and-preview-only deployment model and no further.

### Why it is possible

`SiteReviewComment` has no author column. `AddCommentController` resolves a *project* from the grant and stores nothing about the submitter, so nothing can scope a mutation to its writer. `UpdateCommentHandler`, `DeleteCommentHandler` and `ResolveCommentHandler` all resolve through `findOnePending($commentId, $project)`, which has project scope only. `SiteReviewCorsSubscriber` reflects the request `Origin`, so the endpoints work from any page once a grant exists.

### What bounds the comment half

Policy comes first. `docs/using/live-review.md` and the Connect page document the widget as staging and preview only. The code adds these bounds:

- Only the owner of the project signs in, and the page's origin must be on the project's allowed sites.
- `Addressed` and `Resolved` comments are immune.
- `/api/projects` and `/api/events` need `ROLE_API_AGENT`, which the `site-review` scope does not carry. There is thus no project enumeration and no Mercure JWT.
- `RateLimitSiteReviewWrites` slows churn, but it does not slow a targeted delete.
- The app's own instance ships `SITE_REVIEW_WIDGET_PROJECT` empty in `.env`. `templates/_site_review_widget.html.twig` gates the widget behind `site_review_widget_public or is_granted('ROLE_ADMIN')`.

## The attribution half

The attribution half is accepted too, and it was accepted first. The two halves are easy to confuse. Read this section as attribution only, and the comment half as read, edit and delete only.

The grant names an account and the comment row does not, so `feedback_list` cannot tell an agent who wrote a comment. The compensating control is categorical escalation in the `loupe-site-review` skill. Anything that would change a destination, an identity, a credential or third-party code goes to the human. That control is load-bearing. An agent tested without it applied a link-destination change and a support-email change on its own judgement.

## The origin allowlist

The per-project origin allowlist shipped with the OAuth work, after it was designed in full and deferred once. It gates the sign-in, not the API call. `PrepareWidgetAuthorizationHandler` checks the origin through `SiteOrigins::allows`, and the pop-up posts the code back to that same concrete origin. The API endpoints still accept any `Origin`, because a non-browser caller forges it and the check would buy no guarantee. `LogWidgetOriginMismatch` records a mismatch there and refuses nothing. The design document is a Loupe document named 'Per-project allowed origins for the site-review widget'.

Per-reviewer identity is still open. A reviewer signs in, but the comment row records no account, so the agent still reads an anonymous comment. See the project board.
