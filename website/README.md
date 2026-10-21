# AlternPress project website

The public project presentation at **https://alternpress.com/** (English) and
**https://alternpress.com/it/** (Italian). It contains no blog posts, private
configuration or admin credentials.

`dist/` is the complete, ready-to-serve website: static HTML, one CSS file and a
local SVG favicon. It needs no JavaScript, cookies, remote fonts, CDN, packages,
database or runtime build step. This folder is separate from the blog engine.

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
