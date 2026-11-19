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

## P1 — Required directory types (draft review candidate, Nov 19)

This review promotes the existing `e194221` implementation, not a new parallel
fix. Required content/cache/storage/media paths that are files now emit a
path-specific `not_directory` error, mark the inventory incomplete and suppress
unreliable orphan-photo suggestions. Ordinary nested files remain supported.
The 35-check regression covers all four paths, exact counts, recovery and
unchanged bytes. No files are deleted by diagnostics.

This branch is based on main, not stacked on PR #17. Missing-directory and
special-file fixes remain separate; no combined compatibility is claimed.
PR #17 was rechecked Nov 19: OPEN draft at `6190cfb`, both CI runs successful.
Its Nov 18 test-only follow-up and Nov 19 handoff remain preserved separately.

## P1 — Special filesystem entries (stacked draft review, Nov 19)

Promotes the existing Nov15 `517a3b3` implementation, with interaction tests on
PR18 head `a8e8567`. Nested FIFO/socket/device entries cannot be read as normal
sources: report a path-specific warning, mark the inventory incomplete and
suppress orphan suggestions. No special entry is opened or removed by scanning.
Required top-level paths retain PR18's `not_directory` error; mixed fixtures
verify that an adjacent nested FIFO still gets exactly one warning.

This branch is stacked on `fix/diagnostics-directory-types-review` (PR18), not
on main or PR17. Original local candidate remains preserved. Unix FIFO/socket
fixtures are covered; Windows skips this Unix-only test and devices are not
created. Other publication/URL candidates remain independent and unintegrated.

## P1 — Raw URL entity semantics (stacked review, Nov 19)

Promotes existing `f6291d3`, not a duplicate implementation. Decode HTML entities
once when extracting rendered Markdown attributes; preserve raw CSS/custom-CSS
and cover metadata URL bytes. This prevents false missing/orphan results and
accidental references to decoded-lookalike filenames. Renderer target checks
and partial-inventory interaction cases supplement the original regressions.

Stacked on PR19 head `e611713` (which depends on PR18); neither includes PR17.
No parser or public rendering change. Original local candidate is preserved.
Unix FIFO interaction runs where supported; device/Windows behavior is not
established by this test. Publication/scheme/alias candidates remain separate.

## P1 — Longest URL alias prefix (stacked review, Nov 19)

Promotes existing `2c958f0` (no new resolver implementation): prefer the most
specific matching alias prefix on the same host/port instead of registration
order. Nested install/canonical aliases previously caused false missing targets
and orphan-image suggestions. Segment-boundary matching remains unchanged.
Original60 checks plus8 interaction checks cover PR20 entity-bearing raw CSS
and Markdown targets with both complete and wrong-directory inventories.

Stacked explicitly on PR20 head `a4bebc6` (PR19/18 below it), not on main or PR17.
Original candidate preserved. Scheme-case/publication candidates remain separate;
no combined compatibility claim for them. No public routing changes.

## P1 — Case-insensitive URL scheme defaults (stacked review, Nov19)

Promotes existing `2ea9a74`: lowercase the scheme when inferring default ports
at both alias registration and link resolution. Upper/mixed-case HTTPS must
use443, not80. Explicit ports and host/port matching are unchanged. Original209
checks plus10 interactions cover mixed-case nested canonical aliases, raw entity
retention and partial-inventory orphan suppression.

Stacked on PR21 head `eee6da7` (PR20/19/18 below), not main or PR17. Original
candidate preserved. Publication-timing candidate remains independent. No public
routing change; HTTP(S) URLs are not fetched by diagnostics or these tests.

## P1 — Scheduled diagnostic destinations (stacked review, Nov19)

Promotes existing `bf9e1e3`: reuse the unchanged publication predicate through
`lib/publication.php`; future/invalid-time posts, post attachments and privacy
notices are not accepted as public destinations. Draft/scheduled source images
remain retained. Other page behavior remains unchanged. Updater REQUIRED list
and isolated bootstrap fixtures include the shared module.

Stacked on PR22 head `d6610f2` (PR21/20/19/18 below), not main or PR17.
Original100 diagnostic checks plus8 stack interactions; offline module/load-order
and package dependency tests do not run an update or HTTP health probe. Public
publication policy/function bodies are moved verbatim, not broadened.

## P1 — Missing-tree compatibility promotion (stacked review, Nov19)

Promotes ONLY PR17 lineage `6190cfb` runtime once, plus dependent Nov18 test-only
follow-up `22bb347` (all11 multi-missing subsets). Does not also apply original
`00ec8a2`. Missing trees mark the inventory incomplete and suppress unreliable
orphans, retaining exact errors. PR17 remains unchanged as original review source.

Stacked on PR23 head `568836b` (PR22/21/20/19/18 below). All seven original runtime
gaps are now combined in this review stack, not main/deployed. Single/multi-tree
regressions plus mixed missing/wrong-type/FIFO cases verify publication scheduling,
mixed-case nested aliases and entity-bearing media references without new policy.
The Nov18 follow-up is test coverage, not another runtime fix. Full-release and
HTTP verification remain separate; no release/deployment readiness claim.

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
| Missing required directories leave scan completeness true | `fix/diagnostics-missing-directories-2026-11-13` | `00ec8a2` |
| Required paths of the wrong type leave scan completeness true | `fix/diagnostics-directory-types-2026-11-14` | `e194221` |
| Unsupported filesystem entries are silently skipped | `fix/diagnostics-special-files-2026-11-15` | `517a3b3` |

The scan-completeness gaps can produce unreliable orphan-photo candidates.
Diagnostics never deletes these candidates; manually verify references before
acting on them. The URL and publication candidates affect the same diagnostics
module, while publication also introduces a shared runtime module and updater
packaging requirements. Individual passing tests do not establish combined
compatibility. Re-run the full regression suite on any combined candidate.
The seven branches above identify the original local candidates, not promises
of availability in a released version. Markdown P3 limits remain deliberate.

### Missing-tree review handoff (2026-11-19)

The missing-directory candidate has a separate review lineage; do not count it
as an eighth independent runtime fix:

| Role | Branch | Commit | Base (ancestor) |
| --- | --- | --- | --- |
| Original implementation | `fix/diagnostics-missing-directories-2026-11-13` | `00ec8a2` | `b530505` |
| Review candidate, recorded as draft [PR #17](https://github.com/VantaxDev/alternpress/pull/17) on Nov 17 | `fix/diagnostics-missing-trees-review` | `6190cfb` | `b530505` |
| Test-only follow-up | `test/diagnostics-multiple-missing-trees-2026-11-18` | `22bb347` | `6190cfb` |

The follow-up exercises all 11 combinations of two or more missing required
trees with 77 additional assertions. It depends on the review candidate's
runtime fix and single-tree regression fixture; it is not a standalone fix
against main. Its recorded targeted run passed 203 checks across three scripts
and 43 PHP syntax checks, not the full suite. PR state and remote CI must be
rechecked before any integration; this inventory records local ancestry only.

For review, choose one missing-tree runtime lineage, then assess the test-only
follow-up. Do not apply both the original and review implementations. The
wrong-type and special-file candidates remain independent work with overlapping
scan-completeness code, not dependencies of this follow-up. Combined regression
coverage and integration authorization are still required; none of these local
references establishes deployment readiness.

## Integrated review history

The combined Markdown changes were merged in PR #13, including list starts
and thematic breaks. PR #14 established GPL-3.0-only for current source with
prior MIT notices retained. PR #15 integrated the table/fence boundary.
Preserve those histories and do not reapply stale alternative implementations.
The table/block follow-up is included with the combined feature integration;
the remaining P3 limits above are deliberate, not undelivered ready code.
