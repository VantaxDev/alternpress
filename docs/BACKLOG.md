# Prioritized correctness backlog

Statuses describe the integrated source; deployment is a separate operation.

## P1 — Table/fence boundary (integrated via PR #15)

A table followed immediately by `~~~lang|extra` or a valid backtick equivalent
used to consume the fence and its pipe-bearing content as table rows. Links or
images intended as literal code became active, and the closer could open an
unintended code block over following prose. This is a rendering/diagnostics
correctness defect, not a demonstrated raw-HTML execution vulnerability.

The candidate shares one opening-fence recognizer between normal block parsing
and table continuation. A valid fence ends the table before it is consumed.
Invalid backtick info, short markers and unsupported indentation stay rows.
`tests/markdown-table-fences.php` covers both marker types, indentation, empty
table body, EOF, near closers, CRLF, diagnostics, public/editor rendering and
500 alternating table/fence blocks. No broader table grammar is introduced.

## P2 — Table/block interruption rules (implemented and integration-tested)

Pipe-bearing headings, list items and quotes now end a preceding table without
a blank line, using exactly the same patterns as standalone blocks. Previously
they became rows; extra cells, including real link targets, could be discarded.
`tests/markdown-table-blocks.php` covers exact HTML, header-only tables, supported
indentation, invalid lookalikes, explicit pipe-prefixed rows, CRLF, EOF,
diagnostics, public/editor rendering and 500 alternating tables/blocks.
This follows the P1 fix in the integrated source; no live deployment is implied.

The existing heading/list/quote grammar is preserved, including its indentation
limits and flat containers. To keep a marker-bearing line as a table row, use
an explicit leading `|`. General table escaping and nested containers remain
separate work; they are not broadened by this boundary fix.

## P3 — Deliberate Markdown subset limits

Mixed text/image link labels, multiple images per link, arbitrary nested labels,
parenthesized URL destinations and nested containers remain unsupported.
Do not broaden these implicitly while fixing unrelated escaping or boundaries.

## P1 — Missing required trees (review candidate, 2026-11-17)

This branch promotes the existing Nov 13 runtime fix: missing content, cache,
storage or media now makes the inventory incomplete and suppresses unreliable
orphan-photo candidates. Existing missing-directory errors and counts remain.
`tests/diagnostics-missing-directories.php` verifies all four trees, suppression,
recovery and preservation of media bytes (38 checks). No deletion is performed.
The original candidate is preserved; the other six candidates below are not
included. This is reviewable source, not a merged fix or deployment.

## Pending diagnostics correctness review (not integrated)

The integrated diagnostics still has the following correctness gaps. Local
candidates exist, but are independent changes based on `b530505`, not a combined
or deployment-tested release. Review their overlap before integration; do not
reapply them as new fixes merely because main still exhibits the defects.

| Gap | Existing local candidate | Commit |
| --- | --- | --- |
| Future/invalid-time publications accepted as public link destinations | `fix/diagnostics-scheduled-targets-2026-11-09` | `bf9e1e3` |
| Uppercase HTTP(S) schemes infer incorrect default ports | `fix/diagnostics-url-scheme-2026-11-10` | `2ea9a74` |
| Nested aliases select a shorter prefix before the more specific one | `fix/diagnostics-alias-prefix-2026-11-11` | `2c958f0` |
| Raw CSS/cover URLs are incorrectly HTML-entity-decoded | `fix/diagnostics-raw-url-entities-2026-11-12` | `f6291d3` |
| Missing required directories leave scan completeness true (fix in this review branch) | `fix/diagnostics-missing-directories-2026-11-13` | `00ec8a2` |
| Required paths of the wrong type leave scan completeness true | `fix/diagnostics-directory-types-2026-11-14` | `e194221` |
| Unsupported filesystem entries are silently skipped | `fix/diagnostics-special-files-2026-11-15` | `517a3b3` |

The scan-completeness gaps can produce unreliable orphan-photo candidates.
Diagnostics never deletes these candidates; manually verify references before
acting on them. The URL and publication candidates affect the same diagnostics
module, while publication also introduces a shared runtime module and updater
packaging requirements. Individual passing tests do not establish combined
compatibility. Re-run the full regression suite on any combined candidate.
These branch names identify local review work, not published PRs or promises
of availability in a released version. Markdown P3 limits above remain deliberate.

## Integrated review history

The combined Markdown changes were merged in PR #13, including list starts
and thematic breaks. PR #14 established GPL-3.0-only for current source with
prior MIT notices retained. PR #15 integrated the table/fence boundary.
Preserve those histories and do not reapply stale alternative implementations.
The table/block follow-up is included with the combined feature integration;
the remaining P3 limits above are deliberate, not undelivered ready code.
