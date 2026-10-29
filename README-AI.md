# Discovery and structured data

AlternPress 0.5.0-alpha generates machine-readable descriptions of its public
content automatically. The same Markdown articles used by the public blog are
the source for the structured data and discovery indexes. There is no database,
external service, scheduled job or manual regeneration step.

## Public addresses

These addresses are relative to the installation directory. An installation at
`https://example.com/blog` exposes them under `/blog/`.

| Address | Purpose |
| --- | --- |
| Article HTML | Embedded Schema.org JSON-LD describing the article and its relationships. |
| `/knowledge-graph.jsonld` | JSON-LD graph connecting the site, its published articles and their topics. |
| `/sitemap.xml` | Sitemap index linking the Italian and English sitemaps. |
| `/sitemap-it.xml`, `/sitemap-en.xml` | Canonical public pages and articles, including links to published translations. |
| `/llms.txt` | Markdown directory of public content for tools that support this convention. |
| `/robots.txt` | Crawler instructions and the sitemap address. |

JSON-LD is included in the server-generated HTML; readers do not need to run
JavaScript to retrieve it. The knowledge graph represents relationships declared
by the site and article tags. It does not claim that a search engine has imported
the graph or created a public knowledge panel.

## Publishing workflow

Write a title, summary and relevant tags, then publish the article in the editor.
Each translation has its own publication state. Publishing, editing, deleting or
returning a translation to Draft is reflected when the discovery resources are
next requested. Translations are linked only when the corresponding public
article actually exists in that language.

Existing articles without a `status` field retain the original published
behavior. A date-based slug remains a valid source of the publication date when
the article has no explicit date. Set the editorial date accurately; a future
date does not schedule publication. File modification timestamps are used for
content modification dates, so preserve them when copying unchanged content
between servers.

Drafts, private storage, configuration and revision history are excluded from
the discovery resources. `robots.txt` and the search-indexing setting are crawler
preferences, not access controls; uploaded media remain public at their URLs.

## Canonical address and identity

Set the public site URL in Settings when the site has more than one reachable
domain. For example, use `https://www.example.com` consistently, or
`https://www.example.com/blog` when the canonical installation is in a folder.
The canonical setting does not move the installation or redirect visitors.

An author can be declared explicitly in `config.local.php`:

```php
<?php
return [
    'author_name' => 'Your public author name',
    'author_url' => 'https://www.example.com/en/about',
];
```

Use only an identity and public profile address that apply to the site's
articles. A missing author is not guessed from the brand, contact email or legal
credits. The site name supplies the publisher identity.

## Limits and expectations

These features help tools discover and interpret public content. They do not
guarantee search ranking, AI citations, crawler visits or inclusion in training
data. `llms.txt` is an optional convention, not an access-control or training
consent mechanism. Clear article text, accurate metadata and stable public URLs
remain essential.

## Italiano

Ogni articolo pubblicato riceve automaticamente i dati JSON-LD. Il grafo
`/knowledge-graph.jsonld`, le sitemap e `/llms.txt` vengono costruiti dai contenuti
pubblici correnti. Non occorre rigenerarli dopo aver salvato un articolo.

Titolo, riassunto, tag, data e stato di pubblicazione devono essere corretti.
Italiano e inglese hanno stati indipendenti: una traduzione in bozza non viene
inserita negli indici o indicata come traduzione pubblica. Le vecchie pagine
senza il campo `status` restano pubblicate, come nelle versioni precedenti.

Imposta l'indirizzo pubblico canonico in **Impostazioni** e, se appropriato,
`author_name` e `author_url` in `config.local.php`. Questi strumenti facilitano
la lettura automatica, ma non garantiscono la presenza o le citazioni nelle
risposte delle AI.
