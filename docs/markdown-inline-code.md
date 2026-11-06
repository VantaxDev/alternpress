# Supported Markdown rules and limits

AlternPress uses a small bundled renderer, not the upstream Parsedown library.
The rules below are a bounded Markdown subset, not full CommonMark compliance.
Public rendering and authenticated editor preview share the same renderer.
Raw HTML is escaped and generated URL attributes are validated.

## Inline code

Use a run of backticks at each end. The closing run must have exactly the same
length; shorter or longer runs inside stay literal. For example,
``Use `x` safely.`` contains the code text `x`. To include a backtick, use longer
delimiters: the source ````Use ``a`b`` safely.```` has code text **a`b**.

Unmatched runs stay literal rather than being reinterpreted as smaller pairs.
Remove exactly one ASCII space at each end if both ends contain a space, unless
the entire content is spaces. Other spaces and tabs stay unchanged. HTML,
entities, links and formatting inside code are literal text, never executed.
Headings, lists, quotes, table cells and supported link labels use these rules.
Table separators still need escaping; multiline/container parsing is not added.

Delimiter/padding rules follow the scoped
[CommonMark code-span definition](https://spec.commonmark.org/0.31.2/#code-spans).
Matching uses two scans and a last-run-position lookup, avoiding repeated suffix
searches for unmatched openers. Existing 2 MiB/40,000-line limits remain.

## Fenced code blocks

A fence has at least three backticks or tildes, with zero to three leading
spaces. A closing fence uses the same marker, at least the opening length,
and only trailing spaces/tabs. Shorter, mixed or text-suffixed markers stay
literal inside the block. Unclosed fences remain escaped code through EOF.
At most the opener's indentation is removed from content lines; trailing
spaces/tabs are preserved. Opening info strings are ignored, not inserted as
HTML attributes. Backtick info strings cannot themselves contain backticks.

These are scoped rules from the
[CommonMark fenced-code definition](https://spec.commonmark.org/0.31.2/#fenced-code-blocks),
not support for general nested containers or syntax highlighting.

## Images and linked images

Matched code spans in image descriptions contribute plain text to the escaped
`alt` attribute. For example, ``![Read `x`](/pic.png)`` has alt text “Read x”.
Other formatting markers in image descriptions remain literal. Code text is
restored before attribute escaping; code HTML is never inserted in an alt.
Normal link labels retain their supported inline formatting.

`[![alt](/image.png)](/destination)` is supported when the link label contains
exactly one image, without extra whitespace, text or images. Both URLs use the
existing validation. Optional quoted titles are accepted but ignored.
One non-repeating pass over typed image tokens handles this form; code/anchor
tokens cannot qualify and token HTML is never reparsed.

Mixed text/image labels, multiple images in one link, image-in-image labels,
arbitrarily nested links and URLs with parentheses remain unsupported. Outer
unsupported syntax stays literal around any supported inner element. No nested
anchor is generated. Fenced/inline code is never reinterpreted.

## Diagnostics and tests

Ordered lists preserve their first numeric marker (up to nine digits, including
zero). Later markers do not restart numbering; longer markers retain the legacy
default start of 1. Lists are not a general nested-container implementation.

Thematic breaks use at least three identical `-`, `*` or `_` markers, optionally
separated by spaces/tabs, with at most three leading spaces. They take precedence
over list markers, close a preceding list and interrupt a paragraph. They remain
literal inside fenced code. Mixed markers and trailing text are not breaks.

Diagnostics extracts targets from the renderer's HTML, so literal code and alt
text cannot create fake targets or hide real links with unmatched delimiters.
It checks both actual targets of linked images, without fetching remote URLs.

The standard PHP test loop includes `markdown-inline.php`, `markdown-fences.php`,
`markdown-images.php`, `markdown-linked-images.php`, `markdown-lists.php`,
`markdown-thematic-breaks.php` and `diagnostics-markdown.php`.
They cover escaping, malformed input, token boundaries and bounded large inputs.
Timing is informational, not a wall-clock assertion.

## Italiano

Il codice inline richiede delimitatori di backtick della stessa lunghezza;
sequenze senza chiusura restano letterali. Le fence supportano backtick o tilde:
la chiusura deve usare lo stesso carattere e una lunghezza almeno uguale.
Il codice nell'alt delle immagini diventa testo escapato. Un link può contenere
esattamente una sola immagine, senza altri elementi nell'etichetta. Non si tratta
di un parser CommonMark completo; HTML grezzo e URL non sicuri restano esclusi.
