# Changelog

## Unreleased

- Fix false missing-link reports in `alt-cli links:check` for generated discovery
  resources, including language sitemaps and installations in a subdirectory.

## 0.5.0-alpha — 2026-10-29

- Generate safe Schema.org JSON-LD on published pages and articles, with stable
  identities linking the website, publisher, optional author and tag topics.
- Add automatic sitemap index and language sitemaps, robots.txt, llms.txt and
  a public knowledge-graph.jsonld endpoint. Publishing, editing and withdrawing
  content updates discovery immediately without a build step or external API.
- Respect draft translations, canonical subfolders and disabled search indexing.
- Use article summaries in HTML descriptions and test discovery through PHP/HTTP.
- Preserve static HTML directory indexes in applications sharing the site's root.
- Cache public HTML and prepare optional Brotli quality 11 variants with a local
  encoder; retain gzip and ordinary HTML fallback without requiring Brotli.
- Validate current content fingerprints and publication state before cache reuse,
  including direct Markdown edits and article withdrawal. Keep cache files private
  behind PHP and use exact inline-script CSP hashes rather than cached nonces.
- Add `alt-cli cache:warm` and extend `cache:clear` to prepared public pages.
  Article saves prepare the saved published translations, home, archive and tag
  index; settings and restores invalidate the cache, while other public pages
  are prepared on eligible requests.

## 0.4.0-alpha — 2026-09-30

- Confine file reads/writes to the installation, reject symlinks and malformed private data, and protect complete backups from creation onward.
- Harden session and form validation, password boundaries, upload re-encoding and atomic writes coordinated with restore.
- Hide draft pages and post images, add a nonce-based public Content Security Policy, bound Markdown rendering and remove unsafe email placeholder replacement.
- Reject disguised executable archive filenames, preserve interrupted restore journals and restrict external link checks to public IPv4 endpoints on ports 80/443.
- Add isolated PHP/HTTP security regressions, pinned GitHub CI and deployment/security documentation. The distribution includes generic demo content only.

## 0.3.0-alpha — 2026-09-30

- Settings: private downloadable data backups and validated restore with a dated preview, explicit confirmation, concurrent-change protection and automatic safety backup.
- Settings: recursive permission checks, local Markdown/image references and possible unused photos across both languages, drafts, pages, covers and local CSS.
- New `alt-cli backup:data`, `check` and `check --json` commands; full backups exclude temporary restore staging.
- Documented backup scope, limits, password preservation and recovery; no new external dependencies.

## 0.2.0-alpha — 2026-09-30

- Manage site identity, descriptions, canonical URL, footer and contact details in the dashboard.
- Local custom CSS editor with reset to the bundled theme; the admin stays unaffected.
- Private, portable settings storage, optimistic conflict detection and saved setting history.
- Settings and custom CSS included in backups and preserved during engine updates.

## 0.1.0-alpha — 2026-09-29

- One password-protected admin/editor, Italian and English UI.
- Flat-file Markdown editing, independent drafts and translations, page editing.
- Safe local image upload, cover selection, portable media references.
- Fully bundled styles and Markdown renderer; no external dependencies or database.
- Configurable site identity, portable domain and base path.
- Atomic saves, revision conflict detection and private saved versions.
- CLI for diagnostics, passwords, cache, backups and link checking.
- Existing date-based article addresses preserved.
