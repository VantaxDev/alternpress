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

## Integrated review history

The combined Markdown changes were merged in PR #13, including list starts
and thematic breaks. PR #14 established GPL-3.0-only for current source with
prior MIT notices retained. PR #15 integrated the table/fence boundary.
Preserve those histories and do not reapply stale alternative implementations.
The table/block follow-up is included with the combined feature integration;
the remaining P3 limits above are deliberate, not undelivered ready code.
