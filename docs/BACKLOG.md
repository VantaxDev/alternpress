# Prioritized correctness backlog

Statuses describe source candidates, not merged or deployed behavior.

## P1 — Table/fence boundary (implemented, awaiting review)

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

## P2 — Table/block interruption rules (implemented, awaiting review)

Pipe-bearing headings, list items and quotes now end a preceding table without
a blank line, using exactly the same patterns as standalone blocks. Previously
they became rows; extra cells, including real link targets, could be discarded.
`tests/markdown-table-blocks.php` covers exact HTML, header-only tables, supported
indentation, invalid lookalikes, explicit pipe-prefixed rows, CRLF, EOF,
diagnostics, public/editor rendering and 500 alternating tables/blocks.
This is a local follow-up stacked on the P1 candidate, not merged or deployed.

The existing heading/list/quote grammar is preserved, including its indentation
limits and flat containers. To keep a marker-bearing line as a table row, use
an explicit leading `|`. General table escaping and nested containers remain
separate work; they are not broadened by this boundary fix.

## P3 — Deliberate Markdown subset limits

Mixed text/image link labels, multiple images per link, arbitrary nested labels,
parenthesized URL destinations and nested containers remain unsupported.
Do not broaden these implicitly while fixing unrelated escaping or boundaries.

## Pending integration, not new work

The combined Markdown changes are already proposed in PR #13, including the
list-start and thematic-break fixes. PR #14 separately proposes GPL-3.0-only
with prior MIT notices retained. Preserve those review histories; do not
duplicate their implementation or infer that either PR is merged.
