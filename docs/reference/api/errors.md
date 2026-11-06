---
title: "API error codes"
description: "Every error code of the public Loupe API, with its status and what it means."
---

Every error of the [public API](index.md) is `application/problem+json` with a
`code` member. Each section below is one code, and the `type` of an error links
to its section. Test the `code`, and never the `detail`.

A `422` with the code `validation_failed` adds an `errors` list. Each entry holds
a `field`, a `code` and a `message`. A `402` adds `subscribeUrl`.

## 400 Bad request

### invalid_cursor

The cursor is malformed, or it comes from another list.

### invalid_json

The request body is not valid JSON.

## 401 Not signed in

### api_key_expired

The API key passed its expiry date.

### invalid_credentials

The bearer token is missing, unknown or revoked.

## 402 Payment required

### subscription_required

The account needs an active subscription.

## 403 Forbidden

### credential_not_allowed

This credential cannot do this on this project.

### credential_not_owner_wide

Only a credential that covers every project of the owner can create a project.

## 404 Not found

### api_version_unsupported

The server does not serve this API version. The answer adds a `versions` list.

### artifact_file_not_found

This version of the artifact has no file at this path.

### artifact_not_found

This project has no such artifact.

### artifact_version_not_found

This artifact has no version with this number.

### card_not_found

This project has no card with this number.

### card_not_in_experiment

The experiment does not list a card with this number. Only a card that a pin, a run or a stored run record of the experiment names counts as listed.

### column_not_found

This board has no column with this slug.

### comment_not_found

This document has no such comment.

### decision_not_found

This version of the document holds no decision with that id.

### document_not_found

This project has no such document.

### document_version_not_found

This document has no version with this number.

### experiment_not_found

This project has no experiment with this name. No pin and no run of the project names it.

### feedback_not_found

This project has no such feedback item.

### project_not_found

The credential reaches no project with this id or slug.

### route_not_found

No route matches this path, or a feature flag switches the route off.

## 405 Method not allowed

### method_not_allowed

The path does not take this method.

## 409 Conflict

### already_paused

This card is already unmanaged.

### backlog_locked

Nobody can rename, change, reorder or delete Backlog.

### card_managed

This card is managed, and its workflow does not allow this move. PUT the hold first, and then any move is allowed.

### card_unmanaged

The card is unmanaged, or the workflow is off for the project.

### child_design_refused

The workflow cannot carry out the childDesign choice for this card.

### column_slug_taken

Another column on this board already has the slug that comes from this label.

### column_target_required

This column holds cards. Pass targetColumn.

### columns_changed

The columns changed while the call ran. Read them and try again.

### comment_already_resolved

A person resolved this thread, so it cannot be addressed.

### comment_deleted

This thread is deleted. The owner must restore it first.

### comment_superseded

A newer version superseded this comment. Read the review again.

### comment_version_stale

This comment belongs to an older version. Read the review for the current comment ids.

### decision_stale

The document has a newer version. Show it to the owner, then call again with its versionNumber.

### decisions_unanswered

One or more decisions have no answer yet. Answer them first, or request changes.

### document_archived

An archived document cannot be published. Restore it first.

### epic_children_open

This epic has open child cards. Move each of them to a terminal column first.

### feedback_already_resolved

A person resolved this item, so it cannot be addressed.

### idempotency_in_progress

The first request with this key has not finished. Try again later.

### idempotency_key_reused

This key was used with a different request body.

### kind_not_releasable

A pause of kind rule ends only on its own release condition.

### name_taken

Another project of this owner already has this name.

### no_terminal_column

A board needs at least one terminal column.

### not_paused

This card has no hold or no pause to end.

### pause_changed

The active pause is not the pause named by pauseId.

### series_name_taken

The project already has a series of that name.

### series_ordinal_taken

Another document in that series already holds that ordinal.

### slug_taken

Another project of this owner has a name that gives the same slug.

### verdict_already_withdrawn

The verdict is already withdrawn.

### verdict_changed

The review changed since you read it. Read the review again, and pass its newest verdictId.

### verdict_none

The document has no verdict to withdraw.

### verdict_stale

The document has a newer version. Show it to the owner, then call again with its versionNumber.

## 415 Unsupported media type

### unsupported_media_type

The Content-Type does not fit this operation.

## 422 Unprocessable

### name_invalid

A project name needs at least one letter or digit, because the slug comes from the name.

### name_required

Pass a name, or a remote that gives one.

### validation_failed

One or more fields were refused.

## 429 Too many requests

### rate_limited

Too many requests. Wait for the seconds in Retry-After.
