# AlternPress

[Theme, favicon, cache & compression — quick guide / Guida rapida](QUICKSTART.md)

[Sito del progetto](https://alternpress.com/it/) · [English](README.md) · [Codice e release](https://github.com/VantaxDev/alternpress) · [Sicurezza](SECURITY.md)

**Solo per chi ha qualcosa da dire.**

Un blog Markdown piccolo e autosufficiente. Un unico accesso admin/editor,
italiano e inglese, articoli e foto sul tuo server. Nessun database, Composer,
npm, CDN, font remoto, analytics o servizio esterno.

Versione **0.5.0-alpha**, licenza MIT. La [guida completa in inglese](README.md)
descrive anche struttura, permessi, ripristino e limiti del formato Markdown.

JSON-LD, knowledge graph, sitemap multilingua e `llms.txt` vengono generati
automaticamente dai contenuti pubblicati. Consulta la [guida alla lettura da parte delle AI](README-AI.md).

## Installazione

Servono PHP 8.2+ con le estensioni mbstring, fileinfo, GD, sessioni e JSON,
Apache 2.4 con mod_rewrite e `AllowOverride All` nella cartella del sito.
Phar e zlib servono per i backup; PHP curl soltanto per controllare link esterni.
Sono estensioni del normale ambiente PHP: il progetto non scarica librerie.

La precompressione Brotli può usare l'estensione PHP Brotli oppure un eseguibile
locale, ad esempio `/usr/bin/brotli`. Entrambi sono facoltativi e non inclusi nel
pacchetto: senza Brotli il blog funziona con HTML e, se PHP zlib è disponibile,
gzip. Non vengono contattati servizi esterni.

1. Copia tutti i file della release, compresi quelli nascosti.
2. Personalizza `config.local.php`: puoi creare un file PHP che restituisce un
   array di impostazioni, usando l’esempio della guida inglese.
3. Rendi `content/`, `media/`, `storage/` e `cache/` scrivibili dall’utente PHP
   (`www-data` su Debian/Ubuntu). Mantieni il codice in sola lettura per PHP.
4. Dalla cartella del sito esegui:

   ```sh
   chmod +x alt-cli
   sudo -u www-data ./alt-cli password
   sudo -u www-data ./alt-cli doctor
   ```

5. Apri `https://tuo-dominio/admin/`, oppure
   `https://tuo-dominio/cartella/admin/` se hai usato una sottocartella.

La release pulita **non contiene una password predefinita**. Il primo accesso
si configura via SSH. La versione alpha privata può usare una password di prova,
da sostituire dal pannello prima dell’uso effettivo.

Usa HTTPS. Le regole incluse sono per Apache; nginx richiede regole equivalenti
prima di esporre il sito. Le foto restano limitate a 8 MB. `.htaccess` e `.user.ini` impostano upload a 64 MB,
richieste a 70 MB e memoria PHP a 256 MB. Se l’hosting non permette le direttive
incluse, applica questi valori nella configurazione PHP del server.

## Scrivere

**Articoli → Nuovo articolo** apre l’editor. Scegli un identificativo permanente
con lettere minuscole, numeri e trattini: ad esempio `2026-09-29-una-buona-idea`.
La data è un campo distinto: puoi pubblicare più articoli nello stesso giorno.
Gli indirizzi dei vecchi articoli basati solo sulla data continuano a funzionare.

Le schede Italiano/English hanno ciascuna titolo, testo, descrizione, tag,
copertina e stato bozza/pubblicato. Puoi compilare una lingua sola e tradurre
in seguito. Non c’è traduzione automatica. La data non programma l’uscita:
salvare una versione pubblicata la rende immediatamente visibile.

**+ Foto** carica una fotografia e la inserisce nel testo. Scrivi una descrizione
alternativa e seleziona la foto. **Scegli foto** imposta la copertina. JPG, PNG e
WebP vengono verificati, ridimensionati e riscritti rimuovendo i metadati.
Le foto caricate sono pubbliche tramite il loro indirizzo anche se l’articolo è
ancora in bozza. Gli articoli in bozza, invece, non sono consultabili dal pubblico.

Usa **Salva modifiche** o Ctrl/Cmd+S. Il salvataggio è manuale. Se un’altra scheda
ha modificato l’articolo, il sistema segnala il conflitto senza sovrascriverlo;
puoi copiare il testo che hai scritto e ricaricare.

In **Pagine** modifichi Home, Competenze e Contatti; le pagine sono pubblicate
subito. Gli articoli si ritirano cambiando lo stato in Bozza. Non ci sono cestino,
commenti, ruoli, plugin o gestione di iscritti.

## Cache delle pagine e compressione

AlternPress conserva le pagine HTML pubbliche già preparate nella cartella
privata `cache/`. Prima di usare una copia, PHP verifica a ogni richiesta
l'impronta dei contenuti correnti. Anche le modifiche manuali ai Markdown
vengono rilevate: le richieste continuano a passare da PHP e a controllare lo
stato di pubblicazione.

Quando salvi un articolo dal pannello, vengono preparate le traduzioni appena
salvate che risultano pubblicate, insieme a home, archivio degli articoli e
indice dei tag. Le altre pagine pubbliche vengono preparate alla prima
richiesta GET o HEAD idonea.
Il salvataggio delle impostazioni e il ripristino di un backup invalidano la
cache, senza ricostruire subito l'intero sito. Un articolo riportato in Bozza
non viene servito dalla vecchia cache dopo la verifica del nuovo stato.

Per preparare in anticipo le pagine pubbliche o eliminare i file generati,
esegui dalla cartella del sito, come utente del server web:

```sh
sudo -u www-data ./alt-cli cache:clear
sudo -u www-data ./alt-cli cache:warm
```

`cache:warm` prepara HTML e varianti compresse disponibili sul server.
`cache:clear` svuota sia gli indici degli articoli sia le pagine preparate.
Nessuno dei due comandi modifica gli articoli. Dopo lo svuotamento le pagine
vengono generate su richiesta.

Prima di usare `cache:warm` da CLI, imposta `origin` e `base_path` in
`config.local.php` per l'installazione pubblica. Ad esempio: `origin` =
`https://example.com`, senza cartella, e `base_path` = `''` alla radice del
dominio oppure `'/blog'` in una sottocartella. Un comando CLI non può dedurre
il dominio del visitatore e il percorso dell'installazione. I salvataggi dal
pannello usano invece il contesto della richiesta web effettiva.

AlternPress usa prima l'estensione PHP Brotli, quando disponibile, poi prova
l'eseguibile locale configurato. Entrambi usano qualità fissa **11**. Il percorso
predefinito dell'eseguibile è `/usr/bin/brotli`; se il server usa un percorso
diverso, impostalo in `config.local.php`. Una stringa vuota per `brotli_binary`
disattiva soltanto l'eseguibile; un'estensione PHP Brotli installata resta
disponibile:

```php
<?php
return [
    'page_cache' => true,
    'brotli_binary' => '/usr/bin/brotli', // Usa '' per disattivare solo l'eseguibile.
];
```

Configura soltanto un eseguibile affidabile gestito dall'amministratore del
server. Se l'encoder non è disponibile, la pubblicazione continua a funzionare:
restano gzip, quando disponibile, e HTML normale, secondo le codifiche accettate
dal client. La compressione avviene durante la preparazione della pagina e non
si ripete per ogni risposta invariata. Imposta `page_cache` a `false` per
disabilitare la cache delle pagine pubbliche.

Mantieni `cache/` protetta dall'accesso HTTP diretto. PHP invia l'HTML con la
Content Security Policy corrispondente, basata sugli hash esatti degli script
incorporati, senza conservare e riutilizzare nonce casuali. Il pannello admin
e le risposte private non vengono memorizzati. Vedi [Sicurezza](SECURITY.md).

## Password: cambio e recupero

Nel pannello: **Impostazioni → Cambia password**. Inserisci la password attuale,
quella nuova due volte, quindi premi **Aggiorna password**. Usa 12–72 caratteri
ASCII. Tutte le sessioni vengono invalidate e devi accedere di nuovo.

Se la dimentichi, da SSH entra nella cartella del sito ed esegui:

```sh
sudo -u www-data ./alt-cli password
```

La nuova password è richiesta due volte e non appare a schermo. Se la copia ha
perso il permesso di esecuzione, usa `php alt-cli password`. Non serve conoscere
la password precedente. Il file privato `storage/auth.php` contiene solo il suo
hash; nessuna password in chiaro è salvata nella configurazione.

## Configurazione e trasferimento

Dal pannello **Impostazioni** puoi modificare nome, simbolo del logo, descrizioni
IT/EN, lingua predefinita, numero di articoli in home, URL pubblico,
indicizzazione, footer nelle due lingue, dati legali, email e CSS personalizzato.
**Salva impostazioni** applica tutte le modifiche insieme.

Lascia vuoto l’URL pubblico per riconoscere automaticamente dominio e cartella.
Se lo imposti, includi l’eventuale sottocartella: modifica gli indirizzi canonici
delle pagine, senza spostare il sito o l’accesso al pannello.

Il CSS personalizzato si aggiunge al tema del blog e non si applica al backend.
Usa soltanto risorse locali, senza `@import` o URL esterni. **Ripristina stile
originale** svuota l’editor: salva per rendere effettivo il ripristino. Nessun file
del tema viene sovrascritto.

Queste preferenze sono salvate in `storage/settings.php`, un file privato incluso
nei backup, e prevalgono su `config.local.php`. Conservalo nei trasferimenti.
Le versioni precedenti restano in `storage/history/`. Per tornare a tutte le
impostazioni dei file, fai una copia di `storage/settings.php` e rimuovilo via SSH.

`config.php` contiene i valori di base; **`config.local.php`** contiene i tuoi:
nome, descrizioni IT/EN, contatti, testo legale, lingua iniziale, navigazione,
fuso orario, limiti delle foto. Le lingue supportate sono italiano e inglese.

Lascia `origin` vuoto e `base_path` a `null`: dominio e sottocartella si adattano
automaticamente. Per trasferire l’intera installazione basta copiare tutti i
file, inclusi quelli nascosti, configurazione e dati, e controllare i permessi.
Esegui poi `./alt-cli cache:clear` e `./alt-cli doctor` nella destinazione.

In alpha lascia disattivata **Consenti l’indicizzazione**; attivala nel pannello
del sito definitivo per consentire l’indicizzazione. Questo
parametro non è una protezione di accesso: il blog pubblicato è pubblico.

Per aggiornare **solo il motore**, fai prima un backup e sostituisci `index.php`,
`config.php`, `.htaccess`, `.user.ini`, `alt-cli`, le cartelle `admin/`, `lib/`,
`assets/`, `bin/` e la documentazione. Conserva `config.local.php`, `content/`,
`media/`, `storage/` e i file personali. Non sovrascrivere i tuoi articoli con i
contenuti dimostrativi e non usare una sincronizzazione che cancelli i file
presenti soltanto nella destinazione.

## Backup, ripristino e Check dal pannello

In **Impostazioni → Backup / restore**, premi **Crea backup** e poi **Scarica**.
Il backup dati contiene tutti i Markdown IT/EN, le immagini JPG/JPEG/PNG/WebP/GIF
nelle cartelle dei contenuti e dei media, e le impostazioni del sito (CSS e footer
compresi). Eventuali file di altri formati sono elencati come esclusi. Conserva
una copia anche fuori dal server: l’archivio può contenere bozze e dati personali.

Per ripristinare, apri **Ripristina da un backup**, seleziona il `.tar.gz` e premi
**Controlla archivio**. Verifica data, quantità e rimozioni previste nell’anteprima,
seleziona la conferma e premi **Ripristina backup**. Il sito torna ai dati del
backup; articoli e foto aggiunti dopo vengono rimossi. Prima viene creato un
backup automatico dei dati attuali, disponibile nello stesso elenco.
Password, codice, configurazione server, cronologia e protezioni non vengono
importati né sostituiti. Per salvare anche questi usa il backup completo CLI.

Gli archivi hanno un manifesto SHA-256 e vengono verificati prima e durante il
ripristino. L’anteprima scade dopo 30 minuti; una modifica nel frattempo richiede
una nuova anteprima. Il pannello accetta solo backup dati AlternPress, non i
backup completi CLI o ZIP generici. Limiti: 64 MB compressi, 252 MB di contenuti,
10.000 file, 2 MB per Markdown e 32 MB per immagine; vale anche l’eventuale
limite inferiore mostrato dall’hosting. I limiti rigidi dell’hosting o un arresto
del server possono interrompere un ripristino: conserva il backup automatico
per il recupero manuale. I backup salvati non vengono eliminati automaticamente.

**Check → Controlla il sito** verifica lettura, scrittura e accesso a cartelle e
sottocartelle con l’utente PHP effettivo. Controlla i collegamenti locali nei
Markdown, le copertine, le immagini e le possibili foto orfane, includendo bozze,
pagine, entrambe le lingue e riferimenti nei CSS. Mostra i percorsi da esaminare;
non corregge permessi e non elimina foto. Una foto senza riferimenti rilevati
può comunque essere usata altrove. Le scansioni incomplete sono indicate e non
producono un elenco di orfani. I link esterni non vengono interrogati dal
pannello; usa `./alt-cli links:check --external` per il controllo HTTP.

## alt-cli

| Comando | Operazione |
| --- | --- |
| `./alt-cli status` | Riepilogo di articoli, traduzioni e fotografie |
| `./alt-cli doctor` | Controllo ambiente PHP, configurazione e permessi |
| `./alt-cli cache:clear` | Svuotamento degli indici e delle pagine pubbliche preparate |
| `./alt-cli cache:warm` | Preparazione delle pagine pubbliche HTML e delle varianti compresse disponibili |
| `./alt-cli password` | Impostazione o recupero della password |
| `./alt-cli backup` | Backup completo privato con verifica SHA-256 |
| `./alt-cli backup:data` | Backup dati compatibile con il ripristino dal pannello |
| `./alt-cli check` | Permessi ricorsivi, riferimenti mancanti e possibili foto orfane |
| `./alt-cli check --json` | Lo stesso rapporto in formato JSON |
| `./alt-cli backup:verify FILE` | Controllo dell’integrità di un backup |
| `./alt-cli links:check` | Controllo dei riferimenti locali nel Markdown |
| `./alt-cli links:check --external` | Anche controllo HTTP dei link esterni |
| `./alt-cli help` | Elenco dei comandi |

I backup si trovano in `storage/backups/`, comprendono anche impostazioni e hash
della password, ed escludono cache e backup precedenti. Non pubblicarli su GitHub.
Per ripristinare: verifica il checksum, estrai in una **nuova cartella vuota**,
ripristina i permessi e prova il sito prima di sostituire quello in uso.

Il controllo link non modifica i contenuti. I frammenti dopo `#` non sono
verificati. I controlli esterni usano HEAD e DNS IPv4 pubblico: errori 403, 405,
429 e timeout richiedono una verifica manuale e non dimostrano che il link sia
morto. Le risposte 404/410 sono segnalate come link morti.

## Pubblicazione su GitHub

Parti dal pacchetto pulito della release, con contenuti dimostrativi generici,
licenza e documentazione. Non pubblicare il backup di un’installazione reale:
contiene credenziali cifrate, impostazioni e contenuti del sito. `.gitignore`
esclude configurazione privata, credenziali, cache e foto caricate.

`php tests/run.php` esegue le verifiche di regressione del renderer e dei percorsi.

## Sicurezza e test della versione 0.4

Il codice controlla i percorsi dei file e rifiuta i collegamenti simbolici nelle
cartelle dei dati. La gestione di sessioni, password, moduli e immagini include
controlli contro richieste malformate; le foto vengono riscritte e salvate in modo
atomico. Le pagine in bozza non sono esposte e il sito pubblico applica una
Content Security Policy. Il renderer Markdown applica limiti di dimensione.

Se un processo si interrompe durante un ripristino, conserva il backup automatico
e `storage/restore/<token>/transaction.php` con la cartella `rollback/`: servono
per il recupero. Il pannello non cancella questi dati di recupero. Può essere
necessario un intervento via SSH dopo un arresto improvviso del server.

Per ripetere le verifiche, nella copia del progetto esegui `php tests/run.php`,
i file `tests/security-*.php`, `php tests/backups.php`, `php tests/diagnostics.php`
e i test HTTP `python3 tests/security-http.py`, `python3 tests/discovery-http.py`
e `python3 tests/page-cache-http.py`. I test usano copie temporanee e dati generici.
Python serve soltanto al collaudo HTTP, non al funzionamento di AlternPress.
Il repository esegue questi controlli automaticamente a ogni aggiornamento.

Questa resta una release alpha. Consulta `SECURITY.md` per i requisiti di
installazione e la segnalazione riservata di problemi.
