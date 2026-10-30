# Languages / Lingue

Merge these entries into the existing array returned by **config.local.php**:

```php
<?php
return [
    'languages' => ['en', 'it', 'de'],
    'default_language' => 'en',
];
```

This one list controls public routes, editor tabs, language links, discovery,
diagnostics and cache. Defaults remain `['it', 'en']` with Italian as default.
One language also works: `['de']` with `'default_language' => 'de'`.
An explicit local-file default wins over saved dashboard settings; remove that
entry to make the dashboard default-language selector editable again.

- Write the new language's **Home** in Pages, then its other pages/articles,
  or use Markdown under `content/de/home/index.md` and
  `content/de/post/<slug>/index.md`. No PHP code edits or automatic translation.
- Missing/draft pages and disabled language routes return **404**. Menus,
  language switches and hreflang link only actual published destinations.
  Root negotiation uses enabled languages with a published Home, honoring
  cookie, Accept-Language quality and then default/first available Home.
- Add optional `description['de']` and `tagline['de']` text in the dashboard
  or existing config arrays. Missing localized site text is blank, not translated.
  UI strings ship in Italian/English; other languages use **English UI** and
  their uppercase code as the label. There is no translation service/platform.
- Remove a code from the list to disable it. Existing Markdown, media and
  localized metadata are retained, including in data backups; re-enable the
  same code to use them again. Restores cannot change the configured list.
- Codes: 2–3 lowercase ASCII letters, optional dash-separated alphanumeric
  subtags of 2–8 characters (`en-GB`, `zh-Hant`); maximum 35 characters,
  1–16 unique codes (case-insensitive uniqueness). Default must match an
  enabled code exactly. Invalid lists/codes fail closed before content paths.
- Cache and discovery regenerate from the new configuration automatically.
  There is no existing RSS/Atom feed to configure; none is invented here.

**Italiano:** la lista è unica nel file di configurazione. Aggiungi `de`, crea
la Home tedesca dalle Pagine e scrivi le traduzioni; togli `de` per disabilitarla
senza cancellarne i file. Interfaccia inglese dove manca una traduzione UI,
mai una traduzione automatica dei tuoi contenuti.
