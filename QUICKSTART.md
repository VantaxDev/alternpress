# Four small things / Quattro cose

| | English | Italiano |
| --- | --- | --- |
| Theme / Tema | Copy `assets/theme.example.css` to `assets/theme.css`, then edit its colors. Remove/rename your copy to restore the original. Settings → Custom CSS still wins. | Copia `assets/theme.example.css` in `assets/theme.css` e cambia i colori. Rinomina la copia per tornare all'originale. Il CSS nelle Impostazioni ha precedenza. |
| Favicon | Save your trusted SVG as `assets/favicon.local.svg`. No PHP edits. Remove/rename it to restore the original. | Salva il tuo SVG fidato come `assets/favicon.local.svg`. Nessuna modifica PHP. Rinominalo per ripristinare l'originale. |
| Cache | Automatic, files only. If needed: `sudo -u www-data ./alt-cli cache:clear` from the installation folder. Pages rebuild on request. | Automatica, solo file. Se serve: `sudo -u www-data ./alt-cli cache:clear` nella cartella del sito. Le pagine si rigenerano alla richiesta. |
| Compression / Compressione | Automatic gzip when zlib is available; optional local Brotli (quality 11), otherwise plain HTML. No plugin needed. Check with `./alt-cli doctor`. | Gzip automatico con zlib; Brotli locale facoltativo (qualità 11), altrimenti HTML normale. Nessun plugin. Verifica con `./alt-cli doctor`. |

Use your host's PHP user instead of `www-data` if different. Compression applies
to eligible cached public HTML, not every asset or admin response.

Keep both custom files when updating; Git ignores them. Back them up separately:
the built-in content backup does **not** include `assets/`. Use local resources
only in CSS and a trusted, script-free SVG. File changes refresh page-cache and
asset URLs automatically; a browser may still need a favicon refresh.

Conserva i due file negli aggiornamenti e copiali nel tuo backup: il backup
integrato dei contenuti **non** include `assets/`. Usa risorse CSS locali e SVG
fidati senza script. Cache e URL si aggiornano automaticamente.

## Design decision

The optional example borrows neutral surfaces, thin borders, generous spacing
and a warm red accent from [hatamoto.dev](https://hatamoto.dev/) (inspected
2026-10-30). It keeps system fonts and the existing blog layout, including dark
mode; it does not copy Hatamoto's product UI or add its infrastructure. Existing
sites retain their original look until the example is copied. No theme manager,
new settings, JavaScript, dependencies or database.
