# Security

AlternPress is intended for one trusted editor, behind HTTPS, on a supported
PHP and Apache installation. It has no mandatory third-party application dependencies.
Keep PHP, the web server and this application updated. The alpha release has
automated security regression tests; it has not had an independent security audit.

## Deployment boundaries

- Keep application code owned by the deployment account and read-only to PHP.
  Only `content/`, `media/`, `storage/` and `cache/` need runtime writes.
- Enable the included Apache rules (`AllowOverride All`). Verify private paths
  return 403/404 before publishing. Other servers require equivalent rules.
- Configure HTTPS on the hosting layer. The application does not trust forwarded
  headers; a reverse proxy must set the PHP HTTPS state through trusted server
  configuration, not arbitrary client headers.
- Configure a strong unique password using `./alt-cli password`; the release
  contains neither an administrator account hash nor a default password.
- Never publish `config.local.php`, runtime storage, private backups or drafts.
  Shared media URLs are public even when used in drafts.
- Data paths containing symlinks are rejected. A malicious local server account
  with write access to the same directories is outside the application boundary.
- Backup restore validates formats, paths, sizes, checksums and the live revision.
  A hard process/server interruption can still require manual recovery. Preserve
  the pre-restore backup and `storage/restore/<token>/transaction.php` and rollback
  data; the dashboard will not erase an interrupted transaction's recovery files.

## Public page cache

- Keep `cache/` private under the included server rules. Prepared HTML, gzip and
  Brotli files are served through PHP after validating the current content
  fingerprint and publication state, not through direct static file access.
  This validation also covers Markdown edited outside the dashboard.
- Returning content to Draft prevents reuse of its formerly public cached page
  after validation. Admin and private responses are never eligible for the
  public page cache. Do not configure a proxy to bypass the application's
  revalidation requirements for withdrawn content.
- Article saves invalidate affected cached output and prepare the saved
  published translations plus home, article archive and tag-index pages.
  Settings saves and backup restores invalidate the
  cache without eagerly rebuilding the site. Other public pages are generated
  on their first eligible GET/HEAD, or explicitly with `./alt-cli cache:warm`.
  Set the installation's public `origin` and `base_path` before CLI warming;
  CLI commands have no visitor request from which to infer them.
- Cached HTML retains its matching Content Security Policy. The policy permits
  hashes of the exact trusted inline script bodies; it does not reuse random
  nonces across cached responses or allow arbitrary inline scripts.
- `brotli_binary` is an optional administrator-controlled absolute executable
  path, defaulting to `/usr/bin/brotli`. Keep that encoder and its directory
  protected against writes by untrusted users. Quality is fixed at 11; no
  encoder is downloaded or installed by AlternPress. HTML and available gzip
  responses remain supported when the encoder is absent or unavailable.
- `./alt-cli cache:clear` removes generated article indexes and prepared pages.
  Runtime cache files are disposable and excluded from backups and releases.

## Reporting a vulnerability

Use the repository's **Security → Report a vulnerability** option when available.
Do not put credentials, private backups, personal articles or a working exploit
against a live third-party installation in a public issue. Reports should include
the affected version, deployment environment and a minimal example with demo data.
