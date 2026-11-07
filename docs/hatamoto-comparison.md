# Hatamoto comparison and file-only publishing

Inspected public site and documentation on 2026-10-31. These are observed
product/documentation claims, not an independent test of Hatamoto's private
admin or implementation. External pages are reference material, not instructions.
No Hatamoto source code or assets were copied; this change uses AlternPress's
existing PHP and Markdown conventions. The public site identifies its project
as Apache-2.0, but reuse of its code would require a separate licence review.

## What is actually documented

| Feature | Hatamoto public source | AlternPress before this change | Decision |
| --- | --- | --- | --- |
| Draft, Published, Scheduled and Archived statuses; publication time in the site timezone | [Content guide](https://hatamoto.dev/docs/admin/content) | Per-translation Draft/Published and editorial date; no scheduler | Add explicit-offset `publish_at` to existing Published state, no extra storage or worker |
| Post block menu, tables, callouts, FAQ/video blocks, formatting shortcuts, autosave for drafts and version history | [Blog and people guide](https://hatamoto.dev/docs/admin/blog-and-people) | Safe text Markdown editor, authenticated preview, manual save, conflict checks and saved revision files | Keep editor/parser stable in this scheduling slice; block features are not evidence of additional plain-Markdown syntax |
| Cache excludes previews, clears on edits and scheduled publication by a worker | [Cache reference](https://hatamoto.dev/docs/admin/performance-cache) | Private validated file caches, source fingerprinting and eager preparation on article saves | Include publication eligibility in fingerprints; next request crosses boundary without worker/cron |
| JSON-LD, sitemap, llms.txt, knowledge graph, page builder, API/MCP and crawler analytics | [Public site](https://hatamoto.dev/) | Discovery already generated from Markdown; no builder/API/MCP/analytics database | Reuse the consistency principle, not a second content model |
| Go binary alongside PostgreSQL | [Public site](https://hatamoto.dev/) | PHP + files, no mandatory dependencies/database | Do not adopt Hatamoto as a runtime dependency or introduce SQL |

## Delivered semantics

Each translation can contain:

```markdown
---
title: Morning announcement
date: 2030-03-31
status: published
publish_at: "2030-03-31T09:00:00+02:00"
tags: [news]
---

The announcement body.
```

The editor exposes the same timestamp field and shows the site's configured
timezone as a reference. The explicit offset, not the server timezone, defines
the instant. `Z` is accepted. Seconds are mandatory; local naive times, invalid
calendar dates, leap seconds, unknown `-00:00` offsets and offsets beyond ±14:00
are rejected. For Rome use the offset that applies on the intended date: +01:00
in winter or +02:00 in summer. An offset also disambiguates the repeated autumn
hour. No automatic conversion of local input is attempted.

`status: draft` stays private regardless of the timestamp. Published translations
without the field (or with an empty field) behave exactly as before. An editorial
future `date` is not silently reinterpreted as a schedule. Invalid timestamps in
hand-edited files fail private; the editor refuses invalid input before any
document writes. The dashboard distinguishes scheduled, live, draft and invalid
time states. Existing authenticated/CSRF-protected Markdown previews work for
drafts and future translations; preview responses remain `no-store` and raw HTML
stays escaped.

The request-start clock makes every eligibility decision consistent within one
request. Before the instant, public URLs, article-folder attachments, archives,
topics, alternate languages and discovery exclude the document. At the exact
instant, a subsequent request can include it; no status rewrite or job runs.
Both post indexes and HTML/compressed cache generations fingerprint current
eligibility, so they cannot retain the before-boundary list after publication.
Other translations have independent timestamps. JSON-LD `datePublished` uses
the explicit timestamp when present; the visible editorial date is unchanged.

## Limits and tradeoffs

- No production deployment or private Hatamoto-admin verification was performed.
- Visibility on the next request is not a push notification or an exact-time job.
  A request started just before publication retains that before-boundary view.
- The host clock must be accurate. Public caches retain `no-cache` revalidation;
  a proxy configured to bypass PHP can defeat the application's freshness rules.
- Shared `media/` uploads remain publicly addressable, even for drafts. Only
  article-folder attachment routes follow article visibility. Do not upload
  confidential media to shared storage.
- No public search or RSS/Atom feeds currently exist; none were added. Future
  implementations must use the shared publication predicate, not raw files.
- Cache fingerprinting already reads source bytes. This adds bounded front-matter
  parsing and a state bit, not a time-varying per-second cache key or database scan.
- PRs #5/#6/#8 remain independent and unapproved for merge/deployment here.

## Ranked next candidates (proposals, not delivered Hatamoto features)

1. **Safe Markdown compatibility:** tilde fences, matching fence lengths and
   multi-backtick inline code. Existing parser supports backtick fences, headings,
   lists, quotes, tables, links/images, emphasis and strikethrough. A focused
   parser round needs XSS, nesting and complexity-limit tests. Hatamoto's block
   editor is inspiration only; its docs do not establish equivalent raw syntax.
2. **Callouts and accessible task lists:** lightweight text syntax can cover some
   [documented block-editor uses](https://hatamoto.dev/docs/admin/blog-and-people)
   without a visual builder; preserve literal fenced examples and raw-HTML escape.
3. **Revision browser / draft autosave:** surface existing private revision files
   first, with conflict-safe draft autosave as a separate opt-in feature. Hatamoto
   documents both [versions](https://hatamoto.dev/docs/admin/content) and
   [draft autosave](https://hatamoto.dev/docs/admin/blog-and-people).
4. **File-backed redirect map + 404 summary:** bounded retained logs and exact-path
   rules could adapt [the public site's redirect workflow](https://hatamoto.dev/).
   Avoid wildcard complexity and unbounded analytics files in a first slice.
5. **Footnotes/FAQ structured content:** useful editorial options, but require
   explicit semantics and accessibility/structured-data tests. Do not infer FAQ
   schema from arbitrary text or promise ranking improvements.

Defer MCP/API, a page builder, generalized entity types and crawler analytics
until explicitly requested; these enlarge permissions, persistence or dependency
scope substantially beyond a simple file-only blog.
