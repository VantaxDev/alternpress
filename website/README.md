# AlternPress project website

The public project presentation at **https://alternpress.com/** (English) and
**https://alternpress.com/it/** (Italian). It contains no blog posts, private
configuration or admin credentials.

`dist/` is the complete, ready-to-serve website: static HTML, one CSS file and a
local SVG favicon. It needs no JavaScript, cookies, remote fonts, CDN, packages,
database or runtime build step. Apache SSI renders the business footer year
on each request, including the error page. This folder is separate from the blog engine.

Edit translated copy in `build.py`, then run `python build.py` with Python 3.12+
(standard library only) to regenerate the HTML. Edit `dist/assets/site.css`
directly for styles. Commit both the source and the generated files. Python is
only a maintainer tool; it is not required to serve the website.

For a local preview, run `python -m http.server 8765 --directory dist` and open
http://localhost:8765/. Do not use this static server to expose the PHP blog.

Serve **only `dist/`** as the document root. Redirect HTTP and the `www` hostname
to https://alternpress.com, disable directory indexes and script execution, and
use `404.html` as the 404 response while preserving the HTTP 404 status.

Recommended HTTP headers:

```
Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'none'; font-src 'none'; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

Before changing the served directory, back up the existing installation and
server configuration privately. Never upload the previous personal blog or
server backups to this repository. Only this project presentation is served at
alternpress.com; the existing testing installation is independent.

## Business footer (owned website only)

The website business identity is defined in `build.py`, independently of blog
configuration. VAT fragments are assembled by Apache SSI; the final number and
name remain fully readable and indexable. This is not protection from indexing.

Keep `script-src 'none'` and the other headers unchanged. Enable the standard
Apache `include` module, and add only inside this site's existing Directory block:

```apache
Options IncludesNOEXEC
<FilesMatch "^(index|404)\.html$">
    SetOutputFilter INCLUDES
</FilesMatch>
```

Use `Options IncludesNOEXEC` in place of `Options None`; do not enable `Includes`
or CGI execution. Keep script execution denied, `AllowOverride None`, and the
existing redirects and links. Keep `Cache-Control: no-cache` and leave
`SSIETag`/`SSILastModified` off (the defaults), so a year-dependent response is
not incorrectly revalidated against the unchanged source file. The year uses
the server's local timezone and changes on the first request in the new year;
no rebuild, cron, JavaScript or CSP relaxation is needed. Python's simple static
preview does not process SSI: test through Apache for the actual business line.

The blog engine uses the existing escaped `legal` setting and server year. It
ships no business identity. A site owner can concatenate VAT fragments in their
private `config.local.php`; an admin `legal` setting, if present, takes precedence.

### Footer verification

`php tests/footer.php` verifies configurable/empty/escaped identity, exact
Europe/Rome midnight rollover and a new page-cache fingerprint in the new year.
It mocks only the clock in a test namespace; no production clock override exists.

On an Apache host, run the opt-in integration test (no live configuration edits):

```sh
FAKETIME_LIBRARY=/absolute/path/to/libfaketime.so.1 python3 website/test-footer-ssi.py
```

The test creates a disposable loopback-only Apache instance with the standard
`include`, `filter`, `mime`, `dir`, `authz_core` and `mpm_event` modules. libfaketime
is a **test-only** extracted library, not a project/runtime dependency. It freezes
time at 2030-12-31 23:59:59 and 2031-01-01 00:00:00 in Europe/Rome. Both languages
and the error document must display the exact complete identity for each year,
without SSI source or stale ETag/Last-Modified validators, even with a future
If-Modified-Since request header. It never changes the host clock.
