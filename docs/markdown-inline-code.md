# Matching inline code delimiters

Use a run of backticks at each end of inline code. The closing run must have
exactly the same length; shorter or longer runs inside remain literal. For
example, the source `Use ``a`b`` safely.` renders the code text **a`b** between
“Use” and “safely.” This also works in headings, list items, quotes, table cells
and supported link labels. Existing single-backtick spans continue to work.

Unmatched runs stay literal rather than being reinterpreted as smaller pairs.
When both ends of a span contain an ASCII space, remove one at each end unless
the entire content is spaces; other internal spacing/tabs stay unchanged.
HTML, entities and Markdown inside code are displayed literally, never executed.
Raw HTML and unsafe link destinations remain rejected outside code too.

The delimiter/padding rules follow the
[CommonMark code-span definition](https://spec.commonmark.org/0.31.2/#code-spans).
This is a bounded compatibility improvement, **not full CommonMark compliance**:
the existing block parser, paragraph line joining, table-cell splitting and link
syntax are unchanged. In particular, escape table separator pipes as before;
this does not add multiline/container parsing or the separate block-fence work.
Image-description parsing is unchanged, not promised as full inline markup.

No package, database, configuration, network resource or client script is added.
The bundled `Parsedown` class is this project's small renderer, not an upstream
library upgrade. Matching uses two linear scans with a last-position map keyed
by run length, avoiding repeated suffix searches for unmatched openers.
Existing 2 MiB/40,000-line input limits still apply. The PHP regression suite
automatically includes `tests/markdown-inline.php`: delimiter combinations,
literal HTML/unsafe URLs/token sentinels, link labels, fenced examples and large
paired, unmatched and link-heavy inputs. Timing is informational, not a flaky
wall-clock assertion. Public rendering and authenticated preview share it.

## Independent block-fence PR

This change does not include or modify pending block-fence PR #11. Its parser
patch applies alongside these inline changes. One of its tests expects the old
partial single-backtick interpretation of an invalid block opener; when combining
the changes, that expectation must become literal unmatched runs instead. This
was checked only in a disposable combined fixture, not by updating that PR.

## Italiano

Per mostrare un backtick nel codice inline, delimita il frammento con due o più
backtick: apertura e chiusura devono avere esattamente la stessa lunghezza.
Le sequenze di altra lunghezza restano testo del codice; quelle senza chiusura
restano letterali. Il contenuto non viene interpretato come HTML o altro Markdown.
Il parser dei blocchi e le fence non cambiano; non è una promessa di compatibilità
CommonMark completa. Nessun database o nuova dipendenza.
