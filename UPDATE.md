# Code-only updates / Aggiornamenti del solo codice

For the **PHP blog**, not the separate static project website. This is not an
infallibility or zero-downtime guarantee. First install/review this release's
health protocol normally: older installations are rejected, not bootstrapped
silently. Keep a reviewed copy of `bin/update.php` **outside the webroot**;
recovery must not depend on the broken code it is repairing.

## Commands

On a trusted, reviewed release tree, build the code-only package:

```sh
php /opt/alternpress-update.php pack --source /opt/reviewed-release --output /opt/release.ap.gz
```

Record the printed SHA-256 through an independently trusted channel. A checksum
from the same compromised download does not authenticate its publisher. Then:

```sh
php /opt/alternpress-update.php apply --site /var/www/blog --state /var/backups/blog-update --url https://example.org/it --package /opt/release.ap.gz --sha256 TRUSTED_SHA256
php /opt/alternpress-update.php rollback --site /var/www/blog --state /var/backups/blog-update --url https://example.org/it
```

Use your **real dynamic language-page URL**, including any installation prefix;
redirects, cached 200s and static pages do not pass. Run as the deployment owner,
with permission to preserve code ownership and write the site's private storage.
State/backups must be private (0700), truly outside every served web directory.

Exit codes: **0** success; **1** preflight/staging rejected, code unchanged;
**2** update failed, previous code restored and HTTP-verified; **3** recovery
required (maintenance stays on); **4** competing update/editorial write, retry.
Structured details and durable journals live in `--state` (`update.log`,
`current.json`, transaction directories). Do not delete them or the lock files.

## Recovery and limits

After SIGKILL, reboot or power loss, restore the service/filesystem first, then:

```sh
php /opt/alternpress-update.php recover --site /var/www/blog --state /var/backups/blog-update --url https://example.org/it
```

- `recover` uses the **same** state directory/URL, restores only journaled code,
  and verifies the previous dynamic page before releasing maintenance. If the
  backup, disk, permissions or old service are broken, exit 3 keeps the evidence;
  repair that cause and retry. A dead process cannot execute rollback itself:
  recovery after reboot needs an operator or an independently configured monitor.
- Contents, uploads, both config files, local theme/favicon, access rules and
  unrelated local files are **never packaged or restored**. Later editorial
  writes survive rollback. Application writes are excluded by its existing lock;
  code changed externally during a transaction is not overwritten blindly.
- Unix, local filesystem with working atomic rename, flock and directory fsync;
  PHP 8.2+, existing blog extensions, `proc_open` and `allow_url_fopen`, with CLI
  memory_limit at least 96 MiB (or -1). Packages are bounded to 4 MiB compressed,
  8 MiB decoded code and 128 files. Both CLI
  and web runtime are checked. Web OPcache must be disabled or expose status with
  timestamp validation enabled and revalidate frequency 0. No automatic ini,
  server configuration, dependency install or restart is performed.
- Staging is linted before maintenance. Activation/rollback uses **atomic
  per-file** replacements, not an atomic whole-tree switch. The bootstrap gate
  blocks new requests; already-running reads may fail during replacement, and
  broken bootstrap code can return 500 until rollback. No shared-data symlinks or
  schema migrations are introduced. Deploy only reviewed, data-format-compatible
  releases; a trusted package's own destructive application code is not sandboxed.
- HTTP checks use fresh private challenges, release identity, runtime readiness,
  cache bypass and a rendered HTML page. Default 3 retries, 3-second request
  total per-request deadline, including DNS/headers, enforced by a bounded PHP
  worker (`--attempts 1..5`, `--timeout 1..10`). OS/storage failures, other pages and
  future failures are not comprehensively monitored. A reverse proxy must forward
  the challenge request and honor no-store. Backups are retained, not auto-pruned.

**Italiano:** crea il pacchetto da codice revisionato, verifica lo SHA-256 fidato
e usa `apply`. Se la nuova pagina dinamica non supera i controlli, viene rimesso
solo il codice precedente, mai un vecchio backup dei tuoi contenuti. Dopo
un'interruzione usa `recover`; `rollback` annulla manualmente l'ultimo update.
In caso di uscita 3 correggi la causa e ripeti il recupero: non cancellare i marker.
