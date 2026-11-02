"""Owned marketing-site notice drafts; never a policy for third-party installs.
Release only together with verified public-cookie removal. Required owner facts
still to confirm are recorded in notices/REVIEW.md, not invented in this copy.
"""
from html import escape
from pathlib import Path

BUSINESS = ('<p>© <!--#config timefmt="%Y" --><!--#echo var="DATE_LOCAL" --> '
            'SERVER TAMER DI DANIELE VANTAGGIATO - P.IVA '
            '<!--#set var="vat_a" value="043" --><!--#set var="vat_b" value="706" -->'
            '<!--#set var="vat_c" value="00274" -->'
            '<!--#echo var="vat_a" --><!--#echo var="vat_b" --><!--#echo var="vat_c" --></p>')
COPY = {
 'it': {
  'title': 'Privacy e cookie', 'back': 'Torna al sito', 'other': 'English',
  'body': '''<h1>Privacy e cookie</h1>
<p>Questa pagina riguarda la presentazione pubblica alternpress.com, non tutte le installazioni del software AlternPress.</p>
<h2>Titolare e contatti</h2><p>SERVER TAMER DI DANIELE VANTAGGIATO. Per richieste sui dati personali: <a href="mailto:info@vantax.eu">info@vantax.eu</a>.</p>
<h2>Cookie e risorse</h2><p>Questa presentazione statica non imposta cookie applicativi e non utilizza storage del browser, analytics, pubblicità personalizzata, pixel o font remoti. I collegamenti IT/EN indicano direttamente la lingua nell’URL. Non significa che il server non riceva dati tecnici.</p>
<p>L’area amministrativa del sito separato vantax.eu utilizza una sessione tecnica protetta per accesso e sicurezza; questa presentazione non la imposta.</p>
<h2>Dati delle richieste e sicurezza</h2><p>Per consegnare le pagine il server riceve l’indirizzo IP e le informazioni inviate con la richiesta. I log Apache includono data e ora, IP, URL richiesto, esito, quantità di dati, Referer e User-Agent; sono utilizzati per erogazione e sicurezza del servizio, non per analisi comportamentale o pubblicità.</p>
<p>La configurazione di rotazione dei log Apache prevede 14 archivi e rotazione giornaliera in presenza di dati. Il numero di rotazioni non definisce da solo un termine massimo per archivi inattivi o backup, che richiedono gestione distinta.</p>
<h2>Collegamenti e diritti</h2><p>I link a GitHub o ad altri siti non sono incorporamenti: seguendoli si accede ai servizi dei rispettivi gestori, con trattamenti distinti.</p>
<p>Per esercitare i diritti di accesso, rettifica, cancellazione, limitazione e opposizione nei casi previsti, contattare il titolare. È possibile presentare reclamo al <a href="https://www.garanteprivacy.it/">Garante per la protezione dei dati personali</a>.</p>''',
 },
 'en': {
  'title': 'Privacy and cookies', 'back': 'Back to the website', 'other': 'Italiano',
  'body': '''<h1>Privacy and cookies</h1>
<p>This page covers the public alternpress.com presentation, not every installation of the AlternPress software.</p>
<h2>Controller and contact</h2><p>SERVER TAMER DI DANIELE VANTAGGIATO. For requests about personal data: <a href="mailto:info@vantax.eu">info@vantax.eu</a>.</p>
<h2>Cookies and resources</h2><p>This static presentation sets no application cookies and uses no browser storage, analytics, personalised advertising, pixels or remote fonts. IT/EN links include the language directly in the URL. This does not mean the server receives no technical data.</p>
<p>The administrative area of the separate vantax.eu site uses a protected technical session for access and security; this presentation does not set it.</p>
<h2>Request information and security</h2><p>To deliver pages the server receives the IP address and information sent with the request. Apache logs include time, IP, requested URL, status, data volume, Referer and User-Agent; they are used for service delivery and security, not behavioural analytics or advertising.</p>
<p>The Apache log rotation configuration specifies 14 archives, rotating daily when data is present. A rotation count alone is not a maximum retention period for inactive archives or backups, which require separate management.</p>
<h2>External links and rights</h2><p>GitHub and other website links are not embeds: following them opens services operated by other providers with separate processing.</p>
<p>To exercise access, rectification, erasure, restriction and objection rights where applicable, contact the controller. A complaint may be submitted to the <a href="https://www.garanteprivacy.it/">Italian data protection authority</a>.</p>''',
 },
}

def build_notices(root: Path):
 for lang, copy in COPY.items():
  home = '/it/' if lang == 'it' else '/'
  other = '/privacy/' if lang == 'it' else '/it/privacy/'
  target = root / ('it/privacy' if lang == 'it' else 'privacy') / 'index.html'
  target.parent.mkdir(parents=True, exist_ok=True)
  target.write_text(f'''<!doctype html>
<html lang="{lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{escape(copy['title'])} — AlternPress</title><link rel="stylesheet" href="/assets/site.css"><link rel="icon" href="/assets/mark.svg" type="image/svg+xml"></head>
<body><header class="site-header wrap"><a class="brand" href="{home}">alternpress.</a><nav aria-label="Language"><a href="{other}">{copy['other']}</a></nav></header><main class="wrap section prose">{copy['body']}</main>
<footer class="site-footer wrap"><div><a href="{home}">{copy['back']}</a>{BUSINESS}</div><nav aria-label="Resources"><a href="https://github.com/VantaxDev/alternpress">GitHub</a> · <a href="https://github.com/VantaxDev/alternpress/blob/v0.5.0-alpha/LICENSE">MIT</a> · <a href="{other}">{copy['other']}</a></nav></footer></body></html>
''', encoding='utf-8')
