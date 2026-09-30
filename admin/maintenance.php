<?php
declare(strict_types=1);
if (!defined('AP_ROOT') || !ap_logged_in()) { http_response_code(404); exit; }
$pendingRestore = null;
if (!empty($_SESSION['restore_token'])) {
    try { $pendingRestore = ap_restore_pending($_SESSION['restore_token']); }
    catch (RuntimeException $e) { unset($_SESSION['restore_token']); echo '<p class="notice error">' . h($e->getMessage()) . '</p>'; }
}
$dataBackups = ap_backup_list();
$report = $_SESSION['diagnostics'] ?? null;
?>
<section class="settings-card maintenance-card" id="backups">
<p class="eyebrow"><?= h(t('UNA COPIA DELLE TUE PAROLE', 'A COPY OF YOUR WORDS')) ?></p>
<h2>Backup / restore</h2>
<p class="subtle"><?= h(t('Salva articoli, pagine, fotografie e impostazioni del sito, inclusi CSS e footer. Scarica una copia e conservala anche fuori dal server.', 'Save articles, pages, photographs and site settings, including CSS and footer. Download a copy and keep it off the server too.')) ?></p>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="backup_create"><button type="submit"><?= h(t('Crea backup', 'Create backup')) ?></button></form>
<?php if ($dataBackups): ?>
<details class="backup-list" open><summary><?= h(t('Backup disponibili', 'Available backups')) ?> (<?= count($dataBackups) ?>)</summary>
<div class="table-scroll"><table><thead><tr><th><?= h(t('Archivio', 'Archive')) ?></th><th><?= h(t('Dimensione', 'Size')) ?></th><th><?= h(t('Download', 'Download')) ?></th></tr></thead><tbody>
<?php foreach ($dataBackups as $backup): ?><tr><td><span class="file-path"><?= h($backup['name']) ?></span><?php if (!empty($backup['skipped'])): ?><details><summary><?= count($backup['skipped']) ?> <?= h(t('file esclusi', 'excluded files')) ?></summary><ul><?php foreach ($backup['skipped'] as $skipped): ?><li class="file-path"><?= h($skipped) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td><td><?= number_format($backup['bytes'] / 1048576, 2) ?> MB</td><td><a href="<?= h(admin_url(['page' => 'settings', 'download_backup' => $backup['name']])) ?>"><?= h(t('Scarica', 'Download')) ?></a></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php else: ?><p class="subtle"><?= h(t('Nessun backup dati creato dal pannello.', 'No data backups created from the dashboard yet.')) ?></p><?php endif; ?>
<details class="restore-upload"<?= $pendingRestore ? ' open' : '' ?>><summary><?= h(t('Ripristina da un backup', 'Restore from a backup')) ?></summary>
<p class="settings-help"><?= h(t('Carica un backup dati creato dal pannello o con alt-cli backup:data. Prima vedrai un riepilogo: il caricamento non cambia il sito. Password, codice del programma e configurazione del server restano quelli attuali.', 'Upload a data backup made from the dashboard or with alt-cli backup:data. You will see a summary first: uploading does not change the site. Your password, application code and server configuration stay as they are.')) ?></p>
<form method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="action" value="restore_prepare"><input type="hidden" name="MAX_FILE_SIZE" value="<?= ap_upload_limit() ?>"><label for="restore-archive"><?= h(t('Backup dati .tar.gz', 'Data backup .tar.gz')) ?> · <?= h(t('massimo', 'up to')) ?> <?= floor(ap_upload_limit() / 1048576) ?> MB</label><input type="file" id="restore-archive" name="archive" accept=".tar.gz,application/gzip,application/x-gzip" required><button class="secondary" type="submit"><?= h(t('Controlla archivio', 'Review archive')) ?></button></form>
</details>
<?php if ($pendingRestore): ?>
<div class="restore-preview" id="restore-preview"><h3><?= h(t('Prima di ripristinare', 'Before restoring')) ?></h3>
<p class="subtle"><?= h(t('Backup del', 'Backup created')) ?> <?= h($pendingRestore['created_at']) ?></p>
<p><?= h(t('L’archivio contiene', 'The archive contains')) ?> <strong><?= (int) $pendingRestore['documents'] ?></strong> <?= h(t('file Markdown e', 'Markdown files and')) ?> <strong><?= (int) $pendingRestore['images'] ?></strong> <?= h(t('fotografie, più le impostazioni del sito.', 'photographs, plus site settings.')) ?></p>
<p class="settings-help"><?= h(t('I dati saranno riportati al momento del backup. Gli articoli e le foto aggiunti dopo saranno rimossi dall’installazione e conservati nel backup automatico prima del ripristino.', 'Your data will return to the backup state. Articles and photos added later will be removed from the installation and kept in the automatic backup made before restoring.')) ?></p>
<p class="settings-help"><?= (int) ($pendingRestore['remove_documents'] ?? 0) ?> <?= h(t('file Markdown e', 'Markdown files and')) ?> <?= (int) ($pendingRestore['remove_images'] ?? 0) ?> <?= h(t('foto attuali non sono presenti nell’archivio.', 'current photographs are absent from the archive.')) ?></p>
<?php if (!empty($pendingRestore['skipped'])): ?><p class="settings-help"><?= count($pendingRestore['skipped']) ?> <?= h(t('file di formato diverso restano al loro posto e sono esclusi dal ripristino.', 'files in other formats stay in place and are excluded from the restore.')) ?></p><?php endif; ?>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="restore_apply"><input type="hidden" name="restore_token" value="<?= h($pendingRestore['token']) ?>"><label class="checkbox-label"><input type="checkbox" name="confirm_restore" value="1" required> <?= h(t('Confermo la sostituzione dei dati mostrati.', 'I confirm replacement of the data shown.')) ?></label><button type="submit"><?= h(t('Ripristina backup', 'Restore backup')) ?></button></form>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="restore_cancel"><input type="hidden" name="restore_token" value="<?= h($pendingRestore['token']) ?>"><button class="quiet" type="submit"><?= h(t('Annulla ripristino', 'Cancel restore')) ?></button></form>
<small class="subtle"><?= h(t('Anteprima valida per 30 minuti. Se nel frattempo salvi modifiche al sito, carica nuovamente l’archivio.', 'Preview valid for 30 minutes. If you save site changes in the meantime, upload the archive again.')) ?></small>
</div><?php endif; ?>
<p class="settings-help backup-footnote"><?= h(t('Questi backup possono contenere bozze e dati personali. Il backup completo del programma, della configurazione e dell’accesso rimane disponibile con alt-cli backup.', 'These backups may contain drafts and personal data. A full backup of the program, configuration and access remains available with alt-cli backup.')) ?></p>
</section>

<section class="settings-card maintenance-card" id="check">
<p class="eyebrow"><?= h(t('UN CONTROLLO, SENZA CAMBIARE NULLA', 'A CHECK WITHOUT CHANGING ANYTHING')) ?></p><h2>Check</h2>
<p class="subtle"><?= h(t('Controlla cartelle e sottocartelle, permessi del server, collegamenti locali nei Markdown, immagini mancanti e fotografie senza riferimenti. Include pagine, copertine e bozze in entrambe le lingue.', 'Check folders and subfolders, server permissions, local Markdown links, missing images and photographs without references. Includes pages, covers and drafts in both languages.')) ?></p>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="check"><button type="submit">Check · <?= h(t('Controlla il sito', 'Check site')) ?></button></form>
<?php if ($report): $summary = $report['summary']; ?>
<div class="check-report" role="status"><p class="subtle"><?= h(t('Ultimo controllo', 'Last check')) ?>: <?= h($report['generated_at']) ?></p>
<div class="check-totals">
<?php foreach (['permission_issues' => t('problemi di permessi', 'permission issues'), 'missing_links' => t('riferimenti mancanti', 'missing references'), 'orphans' => t('possibili foto orfane', 'possibly unused photos')] as $key => $label): ?><div><strong><?= (int) ($summary[$key] ?? 0) ?></strong><span><?= h($label) ?></span></div><?php endforeach; ?>
</div>
<p class="settings-help"><?= (int) $summary['directories'] ?> <?= h(t('cartelle', 'folders')) ?> · <?= (int) $summary['markdown'] ?> Markdown · <?= (int) $summary['links_checked'] ?> <?= h(t('riferimenti locali controllati', 'local references checked')) ?></p>
<?php if (!$report['complete']): ?><p class="notice error"><?= h(t('Controllo parziale: consulta le segnalazioni per i limiti raggiunti.', 'Partial check: review the findings for limits reached.')) ?></p><?php endif; ?>
<?php if ($report['issues']): ?><details open><summary><?= h(t('Segnalazioni da esaminare', 'Findings to review')) ?></summary><ul class="check-issues">
<?php foreach ($report['issues'] as $issue): ?><li><strong><?= h($issue['message']) ?></strong><?php if (!empty($issue['path'])): ?><br><span class="file-path"><?= h($issue['path']) ?></span><?php endif; ?><?php if (!empty($issue['url'])): ?><br><code><?= h($issue['url']) ?></code><?php endif; ?></li><?php endforeach; ?>
</ul></details><?php endif; ?>
<?php if ($report['orphans']): ?><details><summary><?= h(t('Foto senza riferimenti trovati', 'Photos without detected references')) ?></summary><p class="settings-help"><?= h(t('Nessuna foto viene cancellata. Potrebbe essere usata da un altro sito o da un collegamento non riconosciuto.', 'No photos are deleted. They may be used by another site or an unrecognised reference.')) ?></p><ul class="check-issues"><?php foreach ($report['orphans'] as $orphan): ?><li><span class="file-path"><?= h($orphan['path']) ?></span> · <?= number_format($orphan['size'] / 1024, 1) ?> KB</li><?php endforeach; ?></ul></details><?php endif; ?>
<details><summary><?= h(t('Permessi delle cartelle e sottocartelle', 'Folder and subfolder permissions')) ?></summary><p class="settings-help"><?= h(t('Verifica eseguita con l’utente PHP del sito. L = lettura, S = scrittura, A = accesso.', 'Checked as the site’s PHP user. R = read, W = write, A = access.')) ?></p><div class="table-scroll"><table><thead><tr><th><?= h(t('Cartella', 'Folder')) ?></th><th><?= h(t('Permessi', 'Permissions')) ?></th><th><?= h(t('L / S / A', 'R / W / A')) ?></th></tr></thead><tbody><?php foreach ($report['permissions'] as $permission): ?><tr><td class="file-path"><?= h($permission['path']) ?></td><td><code><?= h($permission['mode']) ?></code></td><td><?= $permission['readable'] ? '✓' : '×' ?> / <?= $permission['writable'] ? '✓' : '×' ?> / <?= $permission['searchable'] ? '✓' : '×' ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php foreach ($report['notes'] as $note): ?><p class="settings-help"><?= h($note) ?></p><?php endforeach; ?>
<p class="settings-help"><?= (int) $summary['external_skipped'] ?> <?= h(t('link esterni non interrogati. Il controllo HTTP esterno è disponibile con alt-cli links:check --external. I frammenti # non vengono verificati. Mostrati al massimo 200 segnalazioni/foto e 500 cartelle.', 'external links not requested. External HTTP checks are available with alt-cli links:check --external. URL fragments # are not checked. Up to 200 findings/photos and 500 folders are displayed.')) ?></p>
</div><?php endif; ?>
</section>
