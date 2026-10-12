---
title: "Artifact content domain"
description: "Serve stored artifacts from a separate domain so their scripts run with the full browser features and cannot reach the app."
---

A stored artifact is a page that someone else wrote, such as a prototype. Its
scripts run in your browser. A separate domain keeps those scripts away from
your Loupe session.

## What the content domain is

The content domain is a second domain that points at the same Loupe instance.
Loupe serves each artifact from its own sub-address, for example
`0198f3a0000070008000000000000001.loupeusercontent.com`. The label is the
artifact id without hyphens.

The domain must be a separate registrable domain. Do not use a sub-domain of
your app domain. A browser shares cookies and some storage between sibling
sub-domains. A separate registrable domain gives a hard wall between the app and
the stored pages. Each artifact also gets its own origin, so two artifacts
cannot read the browser storage of each other.

Cookies are different. A stored page can set a cookie for the whole content
domain, and every other artifact on the instance can then read it. Add the
content domain to the Public Suffix List to stop this, as the next section says.

Only stored files are served on the content domain. Any other path answers 404.
The content domain reads no session and sets no cookie.

## Public Suffix List

A browser treats each sub-domain of a domain on the
[Public Suffix List](https://publicsuffix.org/) as a separate site. It then
refuses a cookie that a stored page sets for the whole content domain.
`claudeusercontent.com` and `githubusercontent.com` are on the list for this
reason.

To list your content domain, open a pull request on
[publicsuffix/list](https://github.com/publicsuffix/list) for its PRIVATE
section, and follow the guidelines of that repository. The review takes weeks,
and each browser picks up the change with its next release. Until then, stored
pages can share cookies with each other. Your Loupe session cookie stays safe in
every case, because the content domain is a separate site.

## DNS

Create a wildcard record for the content domain, and point it at the same place
as your app domain.

- With a DigitalOcean DNS zone, add a `*` record of type `A` or `CNAME` to the
  zone of the content domain.
- With another DNS host, add the same `*` record. A bare `A` record for the
  domain itself is optional.

## Certificate

You need a wildcard certificate for `*.<content host>`. A wildcard certificate
needs a DNS challenge. The challenge adds TXT records to the zone, and these
records expire. Renew the certificate and check the TXT records at least 30 days
before the certificate ends. When the certificate lapses, every artifact frame
fails to load.

## Set the host

Set `ARTIFACT_CONTENT_HOST` to the domain, for example `loupeusercontent.com`.
Write the host only. Do not add a scheme or a port. See the
[environment reference](../reference/environment.md).

Loupe also lists `https://*.<content host>` in the `frame-src` of the app, so the
viewer can show the frame. Restart the web and worker processes after the change.

## Allowed sites

A stored page may load fonts, scripts and data only from its own files and from
a short list of sites. The `artifacts.allowed_origins` flag holds the list. Write
the origins separated by spaces. The default is:

```
https://fonts.googleapis.com https://fonts.gstatic.com https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com
```

Loupe keeps only plain `https` origins, such as `https://cdn.example.com` or
`https://*.example.com`. It drops every other token, so a bad value cannot add a
rule to the policy. The viewer shows the list under each artifact. An empty value
allows only the files of the artifact.

## No content domain

When `ARTIFACT_CONTENT_HOST` is empty, Loupe serves the files on the app domain
in a sealed sandbox. Scripts run with an opaque origin. They cannot use cookies
or storage, and some pages do not work. The viewer shows a limited preview
notice.

## Health check

The system status page at `/admin/status` has an *Artifact content domain* row.
It warns when the `artifacts.enabled` flag is on and `ARTIFACT_CONTENT_HOST` is
empty. It shows OK when the host is set. It shows nothing while artifacts are
off. The check does not test DNS or the certificate.
