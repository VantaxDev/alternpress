#!/usr/bin/env python3
"""Regenerate the static project website using only Python's standard library."""
from html import escape
from pathlib import Path

ROOT = Path(__file__).resolve().parent / 'dist'
REPO = 'https://github.com/VantaxDev/alternpress'
VERSION = '0.5.0-alpha'
RELEASE = REPO + '/releases/tag/v' + VERSION
ZIP = REPO + '/releases/download/v' + VERSION + '/alternpress-' + VERSION + '.zip'
TAR = REPO + '/releases/download/v' + VERSION + '/alternpress-' + VERSION + '.tar.gz'
RELEASE_SOURCE = REPO + '/blob/v' + VERSION
DISCOVERY_GUIDE = RELEASE_SOURCE + '/README-AI.md'
QUICKSTART = REPO + '/blob/main/QUICKSTART.md'

COPY = {
    'en': {
        'title': 'AlternPress — For those who have something to say.',
        'description': 'A small, self-contained Markdown blog in Italian and English. Automatic JSON-LD, knowledge graph, sitemaps and llms.txt. Free and open source, on your server.',
        'skip': 'Skip to content', 'nav': ['The idea', 'Features', 'Get started'],
        'eyebrow': 'A smaller way to publish',
        'hero': 'For those who have<br>something to <em>say.</em>',
        'intro': 'A place for your words. A home for your photographs. A small, self-contained alternative to WordPress, with just what you need to publish.',
        'download': 'Download AlternPress', 'source': 'Explore the code',
        'release': f'v{VERSION} · Free & open source · MIT',
        'sample_label': 'Words first. Everything else follows.',
        'sample_title': 'The first word',
        'sample_intro': 'Every good story starts with a little room to think.',
        'sample_body': 'Write something worth keeping. Give it a title, add a photograph, and make it yours.',
        'sample_caption': 'An example article. Your content stays in Markdown.',
        'ribbon': ['No database', 'No external libraries', 'No tracking', 'Your own server'],
        'idea_label': '01 / The idea', 'idea_title': 'Your voice.<br>Your files.<br><em>Your corner of the web.</em>',
        'idea_intro': 'Publishing a thought shouldn’t mean maintaining an ecosystem.',
        'idea_body': 'AlternPress keeps the essentials close: a Markdown editor, your photographs and a simple bilingual blog. There is one admin/editor and one place to write. Your articles are ordinary files, ready to keep, back up or move.',
        'idea_end': 'Small by choice. Yours by design.',
        'features_label': '02 / Made for writing', 'features_title': 'Enough to get your words out.',
        'features': [
            ('Write. Preview. Publish.', 'Simple Markdown, a toolbar and a preview. Keep a draft until it is ready, then publish from your browser.'),
            ('Two languages. One home.', 'Write in Italian, English or both. Each translation has its own text and publication state. You choose the words.'),
            ('Bring your photographs.', 'Upload JPEG, PNG and WebP images. Add a cover and alternative text. Photos are resized and their metadata is removed.'),
            ('Make it feel like you.', 'Change the site name, public URL, footer and custom CSS from the settings. Keep the look as simple as your writing.'),
            ('Keep a way back.', 'Create a data backup from the dashboard. Review an archive before restoring it; the current data is backed up first.'),
            ('Look after the details.', 'The Check button examines folder permissions, missing local references and potentially unused photographs. It reports; you decide what to change.'),
        ],
        'discovery_label': '03 / New in 0.5',
        'discovery_title': 'Your words.<br><em>More ways to read them.</em>',
        'discovery_intro': 'Publish once. Give search engines and AI tools a clearer view of your articles.',
        'discovery_data': '<strong>JSON-LD and a knowledge graph</strong> describe published articles and connect them with your site and their topics.',
        'discovery_indexes': '<strong>Multilingual sitemaps and llms.txt</strong> list public content and link the available Italian and English versions.',
        'discovery_workflow': 'The data follows your writing: publish, edit or withdraw an article and the indexes reflect the change. Drafts stay out. No extra service or manual regeneration.',
        'discovery_link': 'Read the discovery guide',
        'cli_label': '04 / A little control, when you need it', 'cli_title': 'Small site.<br>Useful tools.',
        'cli_body': 'Prefer the terminal? alt-cli helps you clear the cache, recover the admin password, make backups and check links. Included with the project.',
        'cli_link': 'Read the command guide', 'cli_comments': ['Check the installation', 'Clear the cache', 'Create a data backup', 'Check external links'],
        'install_label': '05 / Make a little space', 'install_title': 'From files to your first post.',
        'steps': [
            ('Download & copy', 'Get the release and copy all its files, including hidden files, to your web directory.'),
            ('Set up your site', 'Follow the guide to set permissions, configure the site and set your admin password over SSH. There is no default password.'),
            ('Write your first post', 'Open /admin/, add your words and photographs, and publish. Your blog can live at a domain or in a subfolder.'),
        ],
        'requirements': 'Bring your own hosting',
        'requirements_body': 'PHP 8.2+ with the extensions listed in the guide, Apache 2.4 with rewrite rules, HTTPS and SSH access for setup. No database, Composer or npm needed.',
        'guide': 'Installation guide',
        'faq_label': 'A few things to know',
        'faq': [
            ('Is it ready for everyone?', f'This is version {VERSION}: an early release for trying the project and helping improve it. Test it on your hosting and keep backups before trusting it with important work.'),
            ('Is it really free?', 'Yes. AlternPress is released under the MIT license. You can use it, study it and modify it. The code and release archives are available on GitHub.'),
            ('Can I change the theme and favicon?', f'The latest source includes optional local theme and favicon files. Your existing look stays unchanged until you add them. See the <a href="{QUICKSTART}">short customization guide</a>; these additions are not in the 0.5.0-alpha download above.'),
            ('Does it need outside services?', 'The blog runs on your own PHP hosting. Its CSS, JavaScript and Markdown renderer are included. It does not load CDNs, remote fonts or analytics. Checking external links only contacts those links when you ask it to.'),
            ('How does HTML page caching work?', 'AlternPress caches public HTML and prepares Brotli quality 11 versions when a local encoder is available on your server. The cache refreshes when published content changes. Gzip or ordinary HTML provides a compatible fallback; Brotli is optional.'),
            ('Does it translate my posts?', 'No. You write the Italian and English versions yourself. You can publish one language first and add the other later.'),
            ('Will AI tools cite my articles?', 'Structured data and discovery indexes help tools find and interpret your public content. Each service decides what to read and cite; AlternPress does not promise rankings or AI citations. llms.txt is an optional convention.'),
            ('Who is it for?', 'People who want a personal place to publish, with one admin/editor. There are no teams, user roles, comments or plugin marketplace. The aim is a small, understandable blog.'),
        ],
        'closing_label': 'Less to maintain. More room to write.', 'closing_title': 'Something to say?<br><em>Start here.</em>',
        'alpha_note': 'Early release. Read the guide and keep a backup.',
        'release_link': 'Release notes', 'archive_link': 'Download .tar.gz', 'license': 'MIT license',
        'footer': 'For those who have something to say.', 'footer_note': 'This website uses no cookies, tracking or external assets.',
        'error_title': 'This page isn’t here.', 'error_body': 'You may have followed a link to the previous website. AlternPress now has a new home for the project.', 'error_link': 'Go to the project website',
    },
    'it': {
        'title': 'AlternPress — Solo per chi ha qualcosa da dire.',
        'description': 'Un blog Markdown piccolo e autosufficiente, in italiano e inglese. JSON-LD, knowledge graph, sitemap e llms.txt automatici. Gratuito e open source, sul tuo server.',
        'skip': 'Vai al contenuto', 'nav': ['L’idea', 'Funzioni', 'Per iniziare'],
        'eyebrow': 'Un modo più semplice di pubblicare',
        'hero': 'Solo per chi ha<br>qualcosa da <em>dire.</em>',
        'intro': 'Uno spazio per le tue parole. Una casa per le tue fotografie. Un’alternativa piccola e autosufficiente a WordPress, con quello che serve per pubblicare.',
        'download': 'Scarica AlternPress', 'source': 'Esplora il codice',
        'release': f'v{VERSION} · Gratuito e open source · MIT',
        'sample_label': 'Prima le parole. Il resto viene dopo.',
        'sample_title': 'La prima parola',
        'sample_intro': 'Ogni buona storia inizia con un po’ di spazio per pensare.',
        'sample_body': 'Scrivi qualcosa che valga la pena conservare. Dagli un titolo, aggiungi una fotografia, fallo tuo.',
        'sample_caption': 'Un articolo di esempio. I tuoi contenuti restano Markdown.',
        'ribbon': ['Nessun database', 'Nessuna libreria esterna', 'Nessun tracciamento', 'Il tuo server'],
        'idea_label': '01 / L’idea', 'idea_title': 'La tua voce.<br>I tuoi file.<br><em>Il tuo spazio sul web.</em>',
        'idea_intro': 'Pubblicare un pensiero non dovrebbe significare mantenere un ecosistema.',
        'idea_body': 'AlternPress tiene vicino l’essenziale: un editor Markdown, le tue fotografie e un blog bilingue. Un solo admin/editor, un posto in cui scrivere. Gli articoli sono semplici file, da conservare, salvare o trasferire.',
        'idea_end': 'Piccolo per scelta. Tuo, davvero.',
        'features_label': '02 / Fatto per scrivere', 'features_title': 'Quello che serve alle tue parole.',
        'features': [
            ('Scrivi. Rileggi. Pubblica.', 'Markdown semplice, una barra degli strumenti e l’anteprima. Tieni una bozza finché non è pronta, poi pubblica dal browser.'),
            ('Due lingue. Una casa.', 'Scrivi in italiano, in inglese o in entrambe le lingue. Ogni traduzione ha il proprio testo e stato di pubblicazione. Le parole le scegli tu.'),
            ('Porta le tue fotografie.', 'Carica immagini JPEG, PNG e WebP. Aggiungi copertina e testo alternativo. Le foto vengono ridimensionate e private dei metadati.'),
            ('Dagli il tuo carattere.', 'Modifica nome del sito, URL pubblico, footer e CSS personalizzato dalle impostazioni. Scegli uno stile che lasci spazio alla scrittura.'),
            ('Tieni una strada per tornare.', 'Crea un backup dei dati dal pannello. Controlla un archivio prima di ripristinarlo: i dati attuali vengono prima salvati in automatico.'),
            ('Prenditi cura dei dettagli.', 'Il pulsante Check controlla permessi delle cartelle, riferimenti locali mancanti e foto potenzialmente inutilizzate. Segnala i problemi; sei tu a decidere come intervenire.'),
        ],
        'discovery_label': '03 / Novità della 0.5',
        'discovery_title': 'Le tue parole.<br><em>Più modi per leggerle.</em>',
        'discovery_intro': 'Pubblichi una volta. Aiuti motori di ricerca e strumenti AI a leggere i tuoi articoli.',
        'discovery_data': '<strong>JSON-LD e knowledge graph</strong> descrivono gli articoli pubblicati e li collegano al sito e ai loro argomenti.',
        'discovery_indexes': '<strong>Sitemap multilingua e llms.txt</strong> elencano i contenuti pubblici e collegano le versioni disponibili in italiano e inglese.',
        'discovery_workflow': 'I dati seguono la scrittura: pubblichi, modifichi o ritiri un articolo e gli indici riflettono il cambiamento. Le bozze restano escluse. Nessun servizio aggiuntivo o rigenerazione manuale.',
        'discovery_link': 'Leggi la guida ai dati strutturati',
        'cli_label': '04 / Il controllo, quando ti serve', 'cli_title': 'Un piccolo sito.<br>Strumenti utili.',
        'cli_body': 'Preferisci il terminale? alt-cli ti aiuta a svuotare la cache, recuperare la password admin, creare backup e controllare i link. È incluso nel progetto.',
        'cli_link': 'Leggi la guida ai comandi', 'cli_comments': ['Controlla l’installazione', 'Svuota la cache', 'Crea un backup dei dati', 'Controlla i link esterni'],
        'install_label': '05 / Fai un po’ di spazio', 'install_title': 'Dai file al tuo primo articolo.',
        'steps': [
            ('Scarica e copia', 'Scarica la release e copia tutti i file, compresi quelli nascosti, nella cartella del tuo sito.'),
            ('Prepara il tuo sito', 'Segui la guida per impostare permessi, configurazione e password admin via SSH. Non c’è una password predefinita.'),
            ('Scrivi il primo articolo', 'Apri /admin/, aggiungi parole e fotografie e pubblica. Il blog può stare su un dominio o in una sottocartella.'),
        ],
        'requirements': 'Scegli il tuo hosting',
        'requirements_body': 'PHP 8.2+ con le estensioni indicate nella guida, Apache 2.4 con regole di riscrittura, HTTPS e accesso SSH per la configurazione iniziale. Non servono database, Composer o npm.',
        'guide': 'Guida all’installazione',
        'faq_label': 'Qualche cosa da sapere',
        'faq': [
            ('È già pronto per tutti?', f'Questa è la versione {VERSION}: una prima release per provare il progetto e contribuire a migliorarlo. Verificala sul tuo hosting e conserva backup prima di affidarle contenuti importanti.'),
            ('È davvero gratuito?', 'Sì. AlternPress è distribuito con licenza MIT. Puoi usarlo, studiarlo e modificarlo. Il codice e gli archivi delle release sono disponibili su GitHub.'),
            ('Posso cambiare tema e favicon?', f'Il codice più recente include file locali facoltativi per tema e favicon. Il tuo stile resta invariato finché non li aggiungi. Leggi la <a href="{QUICKSTART}">guida rapida alla personalizzazione</a>: queste novità non sono nel download 0.5.0-alpha qui sopra.'),
            ('Ha bisogno di servizi esterni?', 'Il blog funziona sul tuo hosting PHP. CSS, JavaScript e motore Markdown sono inclusi. Non carica CDN, font remoti o analytics. Il controllo dei link esterni contatta quei link solo quando lo richiedi.'),
            ('Come funziona la cache delle pagine HTML?', 'AlternPress conserva in cache le pagine HTML pubbliche e prepara versioni Brotli a qualità 11 quando sul server è disponibile un encoder locale. La cache si rinnova quando cambiano i contenuti pubblicati. Gzip o HTML normale garantiscono una risposta compatibile; Brotli è facoltativo.'),
            ('Traduce i miei articoli?', 'No. Scrivi tu la versione italiana e quella inglese. Puoi pubblicare prima una lingua e aggiungere l’altra in seguito.'),
            ('Le AI citeranno i miei articoli?', 'Dati strutturati e indici aiutano gli strumenti a trovare e interpretare i contenuti pubblici. Ogni servizio decide cosa leggere e citare: AlternPress non promette posizionamenti o citazioni nelle AI. llms.txt è una convenzione facoltativa.'),
            ('A chi è rivolto?', 'A chi vuole uno spazio personale per pubblicare, con un solo admin/editor. Non ci sono gruppi di utenti, ruoli, commenti o un mercato di plugin. L’obiettivo è un blog piccolo e comprensibile.'),
        ],
        'closing_label': 'Meno da mantenere. Più spazio per scrivere.', 'closing_title': 'Qualcosa da dire?<br><em>Comincia qui.</em>',
        'alpha_note': 'Versione iniziale. Leggi la guida e conserva un backup.',
        'release_link': 'Note della release', 'archive_link': 'Scarica .tar.gz', 'license': 'Licenza MIT',
        'footer': 'Solo per chi ha qualcosa da dire.', 'footer_note': 'Questo sito non usa cookie, tracciamento o risorse esterne.',
        'error_title': 'Questa pagina non c’è.', 'error_body': 'Forse hai seguito un link al vecchio sito. AlternPress ora ha una nuova casa dedicata al progetto.', 'error_link': 'Vai al sito del progetto',
    },
}


def render(lang):
    c = COPY[lang]
    path = '/it/' if lang == 'it' else '/'
    guide = RELEASE_SOURCE + '/' + ('README.it.md' if lang == 'it' else 'README.md')
    nav = ''.join(f'<a href="#{target}">{label}</a>' for target, label in zip(['idea', 'features', 'start'], c['nav']))
    languages = ''.join(f'<a href="{url}" lang="{code}" hreflang="{code}"{(" aria-current=\"page\"" if code == lang else "")}>{code.upper()}</a>' for code, url in [('it', '/it/'), ('en', '/')])
    ribbon = ''.join(f'<li><span aria-hidden="true">↳</span> {item}</li>' for item in c['ribbon'])
    features = ''.join(f'<article class="feature"><span class="number">0{i}</span><h3>{title}</h3><p>{body}</p></article>' for i, (title, body) in enumerate(c['features'], 1))
    commands = ['doctor', 'cache:clear', 'backup:data', 'links:check --external']
    cli = '\n'.join(f'<span class="comment"># {comment}</span>\n<span class="prompt">$</span> ./alt-cli {command}\n' for comment, command in zip(c['cli_comments'], commands))
    steps = ''.join(f'<li><span class="step-number">0{i}</span><h3>{title}</h3><p>{body}</p></li>' for i, (title, body) in enumerate(c['steps'], 1))
    faqs = ''.join(f'<details><summary>{question}<span aria-hidden="true">+</span></summary><p>{answer}</p></details>' for question, answer in c['faq'])
    return f'''<!doctype html>
<html lang="{lang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{c['title']}</title>
  <meta name="description" content="{escape(c['description'], quote=True)}">
  <meta name="theme-color" content="#f6f4ee">
  <link rel="canonical" href="https://alternpress.com{path}">
  <link rel="alternate" hreflang="en" href="https://alternpress.com/">
  <link rel="alternate" hreflang="it" href="https://alternpress.com/it/">
  <link rel="alternate" hreflang="x-default" href="https://alternpress.com/">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="AlternPress">
  <meta property="og:title" content="{c['title']}">
  <meta property="og:description" content="{escape(c['description'], quote=True)}">
  <meta property="og:url" content="https://alternpress.com{path}">
  <meta property="og:locale" content="{'it_IT' if lang == 'it' else 'en_US'}">
  <link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<a class="skip" href="#main">{c['skip']}</a>
<header class="site-header wrap">
  <a class="brand" href="{path}" aria-label="AlternPress home"><span class="brand-mark" aria-hidden="true">a<span>_</span></span>alternpress<span class="brand-dot">.</span></a>
  <nav class="main-nav" aria-label="{'Navigazione principale' if lang == 'it' else 'Main navigation'}">{nav}</nav>
  <nav class="languages" aria-label="{'Lingua' if lang == 'it' else 'Language'}">{languages}</nav>
</header>
<main id="main">
  <section class="hero wrap" aria-labelledby="hero-title">
    <div class="hero-copy">
      <p class="eyebrow"><span class="accent-line" aria-hidden="true"></span>{c['eyebrow']}</p>
      <h1 id="hero-title">{c['hero']}</h1>
      <p class="intro">{c['intro']}</p>
      <div class="actions"><a class="button" href="{ZIP}">{c['download']} <span aria-hidden="true">↓</span></a><a class="text-link" href="{REPO}">{c['source']} <span aria-hidden="true">↗</span></a></div>
      <p class="release-meta">{c['release']}</p>
    </div>
    <figure class="writing-scene" aria-label="{c['sample_caption']}">
      <div class="scene-heading"><span class="tiny-mark" aria-hidden="true">a_</span><span>{c['sample_label']}</span></div>
      <div class="paper">
        <div class="paper-top"><span>article.md</span><span>IT / EN</span></div>
        <div class="paper-content"><span class="markdown-hash" aria-hidden="true">#</span><h2>{c['sample_title']}</h2><p class="paper-lead">{c['sample_intro']}</p><span class="paper-rule" aria-hidden="true"></span><p>{c['sample_body']}</p><span class="cursor" aria-hidden="true"></span></div>
        <div class="paper-bottom"><span>Markdown</span><span aria-hidden="true">↵</span></div>
      </div>
      <figcaption>{c['sample_caption']}</figcaption>
    </figure>
  </section>
  <div class="principles"><ul class="wrap">{ribbon}</ul></div>
  <section class="idea wrap section" id="idea" aria-labelledby="idea-title">
    <div><p class="eyebrow">{c['idea_label']}</p><h2 id="idea-title">{c['idea_title']}</h2></div>
    <div class="idea-copy"><p class="lead">{c['idea_intro']}</p><p>{c['idea_body']}</p><p class="signature">{c['idea_end']}</p></div>
  </section>
  <section class="features-section" id="features" aria-labelledby="features-title"><div class="wrap section">
    <p class="eyebrow">{c['features_label']}</p><h2 id="features-title">{c['features_title']}</h2>
    <div class="features-grid">{features}</div>
  </div></section>
  <section class="idea wrap section" id="discovery" aria-labelledby="discovery-title">
    <div><p class="eyebrow">{c['discovery_label']}</p><h2 id="discovery-title">{c['discovery_title']}</h2></div>
    <div class="idea-copy"><p class="lead">{c['discovery_intro']}</p><p>{c['discovery_data']}</p><p>{c['discovery_indexes']}</p><p>{c['discovery_workflow']}</p><a class="text-link" href="{DISCOVERY_GUIDE}">{c['discovery_link']} <span aria-hidden="true">↗</span></a></div>
  </section>
  <section class="tools" aria-labelledby="tools-title"><div class="wrap section tools-grid">
    <div><p class="eyebrow">{c['cli_label']}</p><h2 id="tools-title">{c['cli_title']}</h2><p>{c['cli_body']}</p><a class="text-link" href="{guide}">{c['cli_link']} <span aria-hidden="true">↗</span></a></div>
    <div class="terminal"><div class="terminal-heading"><span>alt-cli</span><span aria-hidden="true">~/alternpress</span></div><pre><code>{cli}</code></pre></div>
  </div></section>
  <section class="wrap section install" id="start" aria-labelledby="install-title">
    <p class="eyebrow">{c['install_label']}</p><h2 id="install-title">{c['install_title']}</h2>
    <ol class="steps">{steps}</ol>
    <div class="requirements"><div><h3>{c['requirements']}</h3><p>{c['requirements_body']}</p></div><a class="text-link" href="{guide}">{c['guide']} <span aria-hidden="true">↗</span></a></div>
  </section>
  <section class="wrap faq-section" aria-labelledby="faq-title"><h2 id="faq-title">{c['faq_label']}</h2><div class="faq">{faqs}</div></section>
  <section class="closing" aria-labelledby="closing-title"><div class="wrap">
    <p class="eyebrow">{c['closing_label']}</p><h2 id="closing-title">{c['closing_title']}</h2>
    <a class="button" href="{ZIP}">{c['download']} <span aria-hidden="true">↓</span></a>
    <p class="release-meta">{c['release']}</p><p class="alpha-note">{c['alpha_note']}</p>
    <div class="download-links"><a href="{RELEASE}">{c['release_link']}</a><a href="{TAR}">{c['archive_link']}</a><a href="{ZIP}.sha256">SHA-256</a></div>
  </div></section>
</main>
<footer class="site-footer wrap"><div><a class="brand" href="{path}">alternpress<span class="brand-dot">.</span></a><p>{c['footer']}</p></div><div class="footer-right"><nav aria-label="{'Risorse' if lang == 'it' else 'Resources'}"><a href="{REPO}">GitHub ↗</a><a href="{guide}">{c['guide']}</a><a href="{RELEASE_SOURCE}/LICENSE">{c['license']}</a></nav><p>{c['footer_note']}</p></div></footer>
</body>
</html>
'''


def build():
    (ROOT / 'it').mkdir(parents=True, exist_ok=True)
    for lang in COPY:
        (ROOT / ('it/index.html' if lang == 'it' else 'index.html')).write_text(render(lang), encoding='utf-8')
    (ROOT / 'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: https://alternpress.com/sitemap.xml\n', encoding='utf-8')
    (ROOT / 'sitemap.xml').write_text('''<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://alternpress.com/</loc></url>
  <url><loc>https://alternpress.com/it/</loc></url>
</urlset>
''', encoding='utf-8')
    (ROOT / '404.html').write_text('''<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Page not found — AlternPress</title><link rel="icon" href="/assets/mark.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/site.css"></head>
<body><main class="wrap error-page"><a class="brand" href="/">alternpress<span class="brand-dot">.</span></a><p class="eyebrow">404</p><h1>This page isn’t here.</h1><p>You may have followed a link to the previous website.</p><a class="button" href="/">Discover AlternPress →</a><hr><div lang="it"><h2>Questa pagina non c’è.</h2><p>Forse hai seguito un link al vecchio sito.</p><a class="text-link" href="/it/">Scopri il progetto AlternPress →</a></div></main></body></html>
''', encoding='utf-8')
    print('Built English and Italian pages, 404, robots.txt and sitemap.xml.')


if __name__ == '__main__':
    build()
