# AlternPress

[Project website](https://alternpress.com/) · [Italiano](README.it.md) · [Source and releases](https://github.com/VantaxDev/alternpress) · [Security](SECURITY.md)

**For those who have something to say.**

A small, self-contained Markdown blog. One admin/editor. Italian and English.
Your articles are ordinary files. Your photographs stay on your server.

No database. No Composer. No npm. No CDN, remote fonts, analytics or third-party
services. PHP, CSS and JavaScript are included. The Markdown renderer belongs to
the project; its historical `Parsedown` class name is a compatibility name, not
an external package.

Version **0.4.0-alpha**. MIT license. [Guida in italiano](README.it.md).

## What it does

- Password-protected editor with one shared admin identity, no user roles.
- Independent Italian/English translations and publication states.
- Markdown toolbar, preview, title, summary, tags and cover photo.
- Drafts remain inaccessible through public routes and archives.
- Upload JPEG, PNG or WebP; validate, resize, re-encode and strip metadata.
- Edit Home, Skills and About pages in both languages.
- Prevent silent overwrites when two tabs edit the same article.
- Keep previous saved file versions privately under `storage/history/`.
- Run at the domain root or under a folder without editing source code.
- `alt-cli` for checks, password recovery, cache, backups and link checking.

## Requirements

PHP **8.2+** with mbstring, fileinfo, GD, sessions and JSON; Phar and zlib for
CLI backups. The optional external link checker also uses PHP curl. These are
PHP extensions, not downloaded project libraries or services.

Apache 2.4 with mod_rewrite and `AllowOverride All` for the application directory.
Use HTTPS for the public installation. The release targets Apache: an nginx
installation needs equivalent routing and protection rules before deployment.
Do not expose the directory with a generic static server.

## Install

1. Copy the release files, including dotfiles, to the site's directory.
2. Create `config.local.php`, returning only the settings you want to override.
3. Give the PHP web user write access to `content/`, `media/`, `storage/` and
   `cache/`. The application code and config should remain read-only to PHP.
4. Set the password and run the checks:

   ```sh
   chmod +x alt-cli
   sudo -u www-data ./alt-cli password
   sudo -u www-data ./alt-cli doctor
   ```

5. Open `/admin/`, including the site's folder prefix if applicable.

Example permissions on Debian/Ubuntu, from the installation directory:

```sh
mkdir -p content media storage cache
chown -R www-data:www-data content media storage cache
find content media cache -type d -exec chmod 755 {} +
find content media cache -type f -exec chmod 644 {} +
find storage -type d -exec chmod 750 {} +
find storage -type f -exec chmod 640 {} +
```

There is **no default password** in the release. The admin page cannot create an
account publicly. Only the installation owner can set the initial password via
SSH. Enable Apache overrides only for the application directory if they are
currently disabled. The shipped `.htaccess` and `.user.ini` set a 64 MB upload
limit, a 70 MB request limit and a 256 MB PHP memory limit. If the host disallows
these overrides, apply those limits in the host's PHP configuration instead.

## Site settings

Open **Settings** in the admin to edit the site name, logo symbol, descriptions
in both languages, default language, article count, public URL, search indexing,
footer texts, legal credits, contact email and custom CSS. Save once to apply all
changes. A blank public URL preserves automatic domain/folder detection; an
explicit URL, including any subfolder, changes canonical and alternate addresses
only. It does not redirect visitors or move the dashboard.

Custom CSS overrides the public theme and is never loaded in the admin. Only
local resources are allowed; remote URLs and `@import` are rejected. **Restore
original style** clears the editor; save to apply the reset. No theme file is
overwritten. Edits survive code updates and are included in backups.

Panel settings are stored as protected data in `storage/settings.php` and take
precedence over `config.local.php`. Keep this file during migrations. To return
all panel settings to the file defaults, back it up and remove it via SSH. The
previous saved settings are retained privately under `storage/history/`.

`config.php` contains defaults. Advanced file settings belong in **`config.local.php`**,
which is excluded from Git and survives code-only updates. For example:

```php
<?php
return [
    'name' => 'My words',
    'tagline' => [
        'it' => 'Solo per chi ha qualcosa da dire.',
        'en' => 'For those who have something to say.',
    ],
    'description' => ['it' => 'Il mio blog.', 'en' => 'My blog.'],
    'email' => '',
    'legal' => '',
    'default_language' => 'it',
    'origin' => '',
    'base_path' => null,
    'search_indexing' => true,
];
```

Keep `origin` empty and `base_path` null for automatic domain/folder detection.
To enforce a canonical domain, set `origin` to the scheme and hostname only,
without a trailing folder. If deploying behind a proxy, set `origin` explicitly
and configure the web server's HTTPS indication; proxy headers are not blindly
trusted. `languages` supports the IT/EN pair. `default_language` controls fallback
for the visitor. Labels, SEO descriptions, email, legal text, navigation,
timezone and photo limits are configurable.

For a test installation set `search_indexing` to false; set it to true on the
public production installation. Noindex is a search-engine preference, not an
access restriction. The public blog itself is public.

## Write and publish

Open **Articles → New article**. Choose a permanent slug, such as
`2026-09-29-a-good-idea`. The date is separate: several articles can share a date.
An existing date-only slug continues to work.

Use the Italiano/English tabs. A translation can be empty until you need it.
Each translation has its own Draft/Published setting. Saving a published
translation updates it immediately; a future editorial date does not schedule
publication. Switch it back to Draft to remove it from public view.

Upload with **+ Photo**. Add an alternative text, then select a photo to insert
it at the cursor. Use **Choose photo** for a cover. References such as
`@media/0123….png` are resolved at rendering time and survive moving the site.
Uploaded pictures are public at their addresses, even when used in a draft.
The upload directory has no public file listing. Use it for publishable images.

Save explicitly; Ctrl/Cmd+S also saves. The editor warns before leaving with
unsaved changes. A conflicting edit is rejected and your submitted text stays
in the editor, ready to copy before reloading. Pages are published immediately.

The supported Markdown subset includes headings, bold, italic, strikethrough,
links, pictures, quotes, ordered/unordered lists, fenced code and simple tables.
Raw HTML is shown as text. This is a deliberately small parser, not a full
CommonMark implementation. Translations are written by the editor, not automatic.

## Change or recover the password

In the panel, open **Settings → Change password**, enter the current password,
the new password twice, and submit. Use 12–72 ASCII characters. All existing
sessions become invalid; sign in with the new password.

If you forget it, connect with SSH, enter the installation directory and run:

```sh
sudo -u www-data ./alt-cli password
```

Terminal input is hidden and requested twice. Only a salted PHP password hash
is stored in `storage/auth.php`; the password is not in the config file.
If the executable bit was lost during a file copy, use `php alt-cli password`.
For a private noninteractive setup, `./alt-cli password --stdin` accepts one line
from standard input. Avoid putting real passwords in shell command history.

## alt-cli

Run commands from the installation directory, preferably as the web user:

```sh
./alt-cli help
./alt-cli status
./alt-cli doctor
./alt-cli cache:clear
./alt-cli password
./alt-cli backup
./alt-cli backup:verify storage/backups/alternpress-….tar.gz
./alt-cli links:check
./alt-cli links:check --external
```

Backups contain the code, content, pictures, configuration, password hash and
saved history. They exclude the generated cache, login attempts and previous
backups. Archives and SHA-256 sidecars stay in private `storage/backups/` and are
not automatically deleted. `backup:verify` checks integrity and opens the archive;
it does not perform a full restore. Export private backups to your own safe
location periodically.

To restore a full CLI backup, first verify the checksum, extract the archive to a **new empty
directory**, restore the writable-directory ownership above, set a new password
if needed, and run `doctor`. Check the restored site before switching traffic.
Nothing in `alt-cli` overwrites your live installation during a restore.

### Dashboard data backup and restore

**Settings → Backup / restore → Create backup** saves Markdown files in both
languages, raster images (JPG/JPEG/PNG/WebP/GIF) in content/media and all editable
site settings, including CSS and footer. Download the archive and keep a copy
off the server. Other file types are listed as excluded. Backups may contain
drafts and personal data and are never deleted automatically.

Choose **Restore from a backup → Review archive** to upload an AlternPress data
archive. Review its date, counts and proposed removals, then confirm and choose
**Restore backup**. Uploading and previewing do not change your site. Applying
restores the data snapshot and removes supported articles/photos added later.
An automatic backup of the current data is made first and appears in the list.
Your password, application code, server configuration, history and protection
files remain in place. Full CLI archives and generic ZIP files are not accepted
by this data restore flow.

Archives have a SHA-256 manifest and strict path, format and payload validation.
The preview expires after 30 minutes and rejects intervening site changes.
Limits are 64 MiB compressed, 252 MiB content, 10,000 files, 2 MiB per Markdown
file and 32 MiB per image; the dashboard shows any lower hosting upload limit.
Photo uploads still have their separate 8 MiB application limit. PHP execution
timeouts are disabled for bounded backup/restore work, but hosting hard limits
or a server shutdown can interrupt a restore; keep its automatic backup for
manual recovery. Expired abandoned previews are cleaned on the next upload.

`./alt-cli backup:data` creates the same dashboard-compatible archive.
`./alt-cli check` runs recursive permissions, local-reference and unused-photo
checks. Add `--json` for structured output. Run as the PHP user to check effective
permissions. The complete CLI `backup` command remains available separately.

### Dashboard Check

**Settings → Check → Check site** checks folders/subfolders and effective PHP
read, write and traversal permissions, local Markdown links, missing images and
possibly unused photographs. Both languages, pages, covers, drafts, legacy post
images and CSS references are included. Findings show affected paths. This
read-only operation does not change permissions or delete photos. A photograph
may still be used elsewhere; review every candidate yourself. Incomplete scans
are labelled and suppress orphan results. The dashboard skips external HTTP
requests and URL fragments; use `./alt-cli links:check --external` when needed.

`links:check` examines Markdown links, image references and covers, including
drafts. It skips fragment checks, email/tel links and external URLs by default.
`--external` makes HTTP HEAD requests, checks redirects and blocks private network
addresses. It currently resolves public IPv4 addresses. HTTP 403/405/429 and
network errors mean **review**, not necessarily a dead link; only 404/410 are
labelled dead. It never edits articles or deletes links.

## Update or move

For a complete migration, copy the whole installation, **including dotfiles and
`config.local.php`**, to the new domain/folder. Keep directory ownership suitable
for PHP. With automatic URL settings, no source-code changes are needed. Clear
the generated cache and run `doctor` at the destination.

For an upgrade to the engine, back up first, then replace only:

- `index.php`, `config.php`, `.htaccess`, `.user.ini`, `alt-cli`
- `admin/`, `lib/`, `assets/`, `bin/` and the documentation/tests

Preserve **`config.local.php`, `content/`, `media/`, `storage/`**, any personal files
and custom styles. Do not copy the demo `content/` over your real articles.
Do not use a mirror operation that deletes destination files. Keep personal
assets separate from the bundled CSS if you maintain a customized theme.

## Storage and safety

`content/{it,en}/post/{slug}/index.md` holds articles and front matter:

```markdown
---
title: A good idea
date: 2026-09-29
status: draft
tags: [ideas, writing]
excerpt: A short introduction.
cover: @media/0123456789abcdef0123456789abcdef.png
---

Your Markdown goes here.
```

`content/{it,en}/{home,skills,about}/index.md` holds pages.
`media/` holds raster images; `storage/` holds private auth, history and backups;
`cache/` holds disposable indexes. Direct HTTP access to content, storage, config,
CLI and library files is blocked. Login uses secure HTTP-only session cookies on
HTTPS, CSRF checks and a per-IP attempt limit. Re-encoding uploaded images prevents
using an uploaded script as executable code.

To publish the **software** on GitHub, start from the clean release, not a live
site backup. Never commit `config.local.php`, `storage/`, personal photographs or
private articles. The example content in the clean release is generic.

## Checks

`php tests/run.php` runs focused Markdown and path regression checks.
The alpha was also exercised through HTTP for authentication, CSRF, private
paths, draft visibility, bilingual publication, uploads, conflicts and relocation.

## Security regression checks

Version 0.4 hardens filesystem boundaries, session and form validation, image
uploads, draft visibility, Markdown rendering and backup restore. See
[SECURITY.md](SECURITY.md) for the deployment boundary and interruption recovery.

Run every `tests/*.php` file with PHP, followed by
`python3 tests/security-http.py`. Tests create temporary fixtures with generic
data; they do not modify a live installation. Python is used only for HTTP tests,
not by the application. GitHub CI repeats the suite on pushes and pull requests.
