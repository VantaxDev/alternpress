# Changelog

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
