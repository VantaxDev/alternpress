# Security

AlternPress is intended for one trusted editor, behind HTTPS, on a supported
PHP and Apache installation. It has no third-party application dependencies.
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

## Reporting a vulnerability

Use the repository's **Security → Report a vulnerability** option when available.
Do not put credentials, private backups, personal articles or a working exploit
against a live third-party installation in a public issue. Reports should include
the affected version, deployment environment and a minimal example with demo data.
