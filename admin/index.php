<?php
declare(strict_types=1);
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
require dirname(__DIR__) . '/lib/admin.php';
require dirname(__DIR__) . '/lib/backups.php';
require dirname(__DIR__) . '/lib/diagnostics.php';
require __DIR__ . '/maintenance-actions.php';
try { ap_session(); }
catch (Throwable) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'Sessione non disponibile. Riprova più tardi. / Session unavailable. Please try again later.'; exit; }
$ui = in_array($_GET['ui'] ?? '', SUPPORTED_LANGUAGES, true) ? $_GET['ui'] : (in_array($_SESSION['ui'] ?? '', SUPPORTED_LANGUAGES, true) ? $_SESSION['ui'] : 'it');
$_SESSION['ui'] = $ui;
function t(string $it, string $en): string { global $ui; return $ui === 'it' ? $it : $en; }
function admin_url(array $query = []): string { global $ui; return ap_url('/admin/') . '?' . http_build_query(array_merge(['ui' => $ui], $query)); }
function csrf_field(): void { echo '<input type="hidden" name="csrf" value="' . h(ap_csrf()) . '">'; }
function ap_flash_redirect(array $query, string $message): never { $_SESSION['flash'] = $message; header('Location: ' . admin_url($query), true, 303); exit; }
$error = ''; $posted = false;
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$authenticated = ap_logged_in();
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'posts';
$isJson = in_array($action, ['preview', 'upload'], true);
try {
    if ($authenticated && isset($_GET['download_backup'])) ap_admin_download_backup(ap_input_string($_GET, 'download_backup'));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new RuntimeException(t('Il file supera il limite di caricamento del server.', 'The file exceeds the server upload limit.'));
        ap_check_csrf();
        $action = ap_input_string($_POST, 'action');
        if ($action === 'login') {
            if (!ap_login(ap_input_string($_POST, 'password'))) throw new RuntimeException(t('Password non corretta.', 'Incorrect password.'));
            ap_flash_redirect([], t('Benvenuto.', 'Welcome.'));
        }
        if (!$authenticated) { http_response_code(401); throw new RuntimeException(t('Accedi per continuare.', 'Sign in to continue.')); }
        ap_admin_maintenance_action($action);
        if ($action === 'logout') {
            ap_logout(); header('Location: ' . ap_url('/admin/'), true, 303); exit;
        }
        if ($action === 'save') {
            ap_validate_document_input($_POST);
            $posted = true;
            $id = ap_save_document($_POST);
            ap_flash_redirect(['page' => 'edit', 'id' => $id, 'kind' => ($_POST['kind'] ?? '') === 'page' ? 'page' : 'post'], t('Salvato. Le versioni pubblicate sono già online.', 'Saved. Published versions are now live.'));
        }
        if ($action === 'preview') {
            $body = ap_input_string($_POST, 'body');
            if (strlen($body) > 500000 || !mb_check_encoding($body, 'UTF-8')) throw new RuntimeException('Text too long or invalid encoding.');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['html' => markdown_html($md, $body)], JSON_THROW_ON_ERROR); exit;
        }
        if ($action === 'upload') {
            $result = ap_upload(is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : []);
            header('Content-Type: application/json; charset=utf-8'); echo json_encode($result, JSON_THROW_ON_ERROR); exit;
        }
        if ($action === 'password') {
            $currentPassword = ap_input_string($_POST, 'current_password');
            $newPassword = ap_input_string($_POST, 'new_password');
            if ($newPassword !== ap_input_string($_POST, 'confirm_password')) throw new RuntimeException(t('Le nuove password non coincidono.', 'New passwords do not match.'));
            ap_lock(static function() use ($currentPassword, $newPassword) {
                $auth = ap_auth_data();
                if (strlen($currentPassword) > 72 || str_contains($currentPassword, "\0") || !isset($auth['hash']) || !password_verify($currentPassword, $auth['hash'])) throw new RuntimeException(t('La password attuale non è corretta.', 'Current password is incorrect.'));
                ap_set_password($newPassword);
            });
            ap_logout();
            header('Location: ' . admin_url(['changed' => '1']), true, 303); exit;
        }
        if ($action === 'settings') {
            ap_save_settings($_POST, 'site');
            ap_flash_redirect(['page' => 'settings'], t('Impostazioni salvate. Le modifiche sono già visibili sul sito.', 'Settings saved. Your changes are now visible on the site.'));
        }
        if ($action === 'cache') {
            ap_lock(static fn() => ap_clear_post_cache());
            ap_flash_redirect(['page' => 'settings'], t('Cache svuotata.', 'Cache cleared.'));
        }
    }
} catch (Throwable $exception) {
    if (http_response_code() < 400) http_response_code(422);
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : t('Operazione non riuscita. Verifica i dati e riprova.', 'Operation failed. Check the data and try again.');
    if (!($exception instanceof RuntimeException)) error_log('AlternPress: ' . $exception->getMessage());
    if ($isJson) {
        if (http_response_code() < 400) http_response_code(422);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => $error]); exit;
    }
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
if (!$authenticated) $page = 'login';
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$kind = ($_GET['kind'] ?? '') === 'page' ? 'page' : 'post';
if ($posted) { $page = 'edit'; $id = (string) ($_POST['id'] ?? ''); $kind = ($_POST['kind'] ?? '') === 'page' ? 'page' : 'post'; }
?><!doctype html>
<html lang="<?= h($ui) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>AlternPress · <?= h(ap_config('name')) ?></title>
<link rel="icon" href="<?= h(ap_url('/assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= h(ap_url('/assets/base.css') . '?v=' . filemtime(AP_ROOT . '/assets/base.css')) ?>"><link rel="stylesheet" href="<?= h(ap_url('/assets/admin.css') . '?v=' . filemtime(AP_ROOT . '/assets/admin.css')) ?>">
<script src="<?= h(ap_url('/assets/admin.js') . '?v=' . filemtime(AP_ROOT . '/assets/admin.js')) ?>" defer></script>
</head>
<body data-ui="<?= h($ui) ?>" data-endpoint="<?= h(admin_url()) ?>" data-csrf="<?= h(ap_csrf()) ?>">
<header class="app-header"><div class="app-width topbar">
<a class="brand" href="<?= h(admin_url()) ?>"><span class="brand-symbol">a.</span><span>alternpress<small><?= h(t('Solo per chi ha qualcosa da dire.', 'For those who have something to say.')) ?></small></span></a>
<div class="header-tools"><a href="<?= h(admin_url(array_merge($_GET, ['ui' => $ui === 'it' ? 'en' : 'it']))) ?>" lang="<?= $ui === 'it' ? 'en' : 'it' ?>"><?= $ui === 'it' ? 'EN' : 'IT' ?></a>
<a class="site-link" href="<?= h(language_url($ui)) ?>" target="_blank" rel="noopener"><?= h(t('Visita il sito', 'View site')) ?> ↗</a>
<?php if ($authenticated): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="logout"><button class="quiet" type="submit"><?= h(t('Esci', 'Sign out')) ?></button></form><?php endif; ?></div>
</div></header>
<div class="app-width app-layout <?= $authenticated ? '' : 'login-layout' ?>">
<?php if ($authenticated): ?><aside class="sidebar"><p class="eyebrow"><?= h(ap_config('name')) ?></p><nav aria-label="<?= h(t('Pannello', 'Dashboard')) ?>">
<?php foreach (['posts' => t('Articoli', 'Articles'), 'media' => t('Fotografie', 'Photographs'), 'pages' => t('Pagine', 'Pages'), 'settings' => t('Impostazioni', 'Settings')] as $target => $label): ?>
<a href="<?= h(admin_url(['page' => $target])) ?>"<?= ($page === $target || ($page === 'edit' && $target === ($kind === 'page' ? 'pages' : 'posts'))) ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
<?php endforeach; ?></nav><div class="sidebar-note">Markdown.<br><?= h(t('Nessun database.', 'No database.')) ?><br><?= h(t('Le tue parole, i tuoi file.', 'Your words, your files.')) ?></div></aside><?php endif; ?>
<main id="main">
<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="notice success" role="status"><?= h($flash) ?></div><?php endif; ?>
<?php if ($page === 'login'): ?>
<section class="login-card"><p class="eyebrow">ALTERNPRESS / EDITOR</p><h1><?= h(t('Qualcosa da dire?', 'Something to say?')) ?></h1><p class="subtle"><?= h(t('Il tuo spazio per scrivere, senza rumore.', 'Your space to write, without the noise.')) ?></p>
<?php if (isset($_GET['changed'])): ?><div class="notice success"><?= h(t('Password aggiornata. Accedi con quella nuova.', 'Password updated. Sign in with your new password.')) ?></div><?php endif; ?>
<?php if (!ap_auth_data()): ?><p><?= h(t('Prima configurazione: esegui ./alt-cli password via SSH.', 'First setup: run ./alt-cli password over SSH.')) ?></p><?php else: ?>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="login"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><button type="submit"><?= h(t('Entra e scrivi', 'Sign in & write')) ?> →</button></form><?php endif; ?>
<small class="subtle"><?= h(t('Un solo editor. Tutto quello che serve.', 'One editor. Everything you need.')) ?></small></section>
<?php elseif ($page === 'posts'): $rows = ap_all_posts(); ?>
<div class="page-heading"><div><p class="eyebrow"><?= count($rows) ?> <?= h(t('STORIE, TUTTE TUE', 'STORIES, ALL YOURS')) ?></p><h1><?= h(t('I tuoi articoli', 'Your articles')) ?></h1><p class="subtle"><?= h(t('Una buona idea comincia con una pagina vuota.', 'A good idea starts with a blank page.')) ?></p></div><a role="button" href="<?= h(admin_url(['page' => 'edit'])) ?>">+ <?= h(t('Nuovo articolo', 'New article')) ?></a></div>
<label class="sr-only" for="search"><?= h(t('Cerca articoli', 'Search articles')) ?></label><input id="search" type="search" placeholder="<?= h(t('Cerca tra titoli e identificativi…', 'Search titles and slugs…')) ?>" data-search>
<div class="post-list">
<?php foreach ($rows as $row): $translation = $row['translations'][$ui] ?? reset($row['translations']); ?>
<article class="post-row" data-search-row><div><small class="subtle"><?= h($row['date']) ?></small><h2><a href="<?= h(admin_url(['page' => 'edit', 'id' => $row['id']])) ?>"><?= h($translation['meta']['title'] ?? $row['id']) ?></a></h2><small class="slug"><?= h($row['id']) ?></small></div><div class="translation-badges">
<?php foreach (SUPPORTED_LANGUAGES as $lang): $d = $row['translations'][$lang] ?? null; ?><span class="badge <?= !$d ? 'missing' : (ap_published($d['meta']) ? 'published' : 'draft') ?>"><?= strtoupper($lang) ?> · <?= h(!$d ? t('assente', 'missing') : (ap_published($d['meta']) ? t('online', 'live') : t('bozza', 'draft'))) ?></span><?php endforeach; ?>
</div><a class="edit-link" href="<?= h(admin_url(['page' => 'edit', 'id' => $row['id']])) ?>"><?= h(t('Modifica', 'Edit')) ?> →</a></article>
<?php endforeach; ?>
<?php if (!$rows): ?><section class="empty-state"><h2><?= h(t('La prima parola è tua.', 'The first word is yours.')) ?></h2><p><?= h(t('Crea il tuo primo articolo e pubblicalo quando è pronto.', 'Create your first article and publish when you are ready.')) ?></p></section><?php endif; ?>
</div>
<?php elseif ($page === 'edit'): ?>
<?php
$validId = ap_id_valid($id) && ($kind !== 'page' || in_array($id, ['home', 'skills', 'about'], true));
$documents = []; $exists = false; $date = date('Y-m-d');
foreach (SUPPORTED_LANGUAGES as $lang) {
    $doc = $validId ? ap_document($lang, $id, $kind) : ['meta' => [], 'body' => '', 'revision' => hash('sha256', ''), 'exists' => false];
    $exists = $exists || $doc['exists'];
    if ($doc['exists']) $date = $doc['meta']['date'] ?? (preg_match('/^\d{4}-\d{2}-\d{2}/', $id) ? substr($id, 0, 10) : $date);
    if ($posted) { $fields = $_POST['translation'][$lang] ?? []; $doc['meta'] = $fields; $doc['body'] = (string) ($fields['body'] ?? ''); $doc['revision'] = (string) ($fields['revision'] ?? ''); }
    $documents[$lang] = $doc;
}
if ($posted) $date = (string) ($_POST['date'] ?? $date);
?>
<form method="post" action="<?= h(admin_url(['page' => 'edit', 'id' => $id, 'kind' => $kind])) ?>" data-editor>
<?php csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="<?= h($kind) ?>">
<div class="page-heading"><div><p class="eyebrow"><?= h($kind === 'page' ? t('PAGINA', 'PAGE') : t('ARTICOLO', 'ARTICLE')) ?></p><h1><?= h($exists ? t('Dai forma alle parole.', 'Shape your words.') : t('Comincia da un’idea.', 'Start with an idea.')) ?></h1></div><button type="submit" data-save><?= h(t('Salva modifiche', 'Save changes')) ?></button></div>
<div class="editor-meta"><div><label for="slug"><?= h(t('Identificativo / indirizzo', 'Slug / address')) ?></label><input id="slug" name="id" value="<?= h($id) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100" placeholder="<?= date('Y-m-d') ?>-una-buona-idea" required <?= $exists || $kind === 'page' ? 'readonly' : '' ?>><small class="subtle"><?= h(t('Condiviso tra IT e EN. Non cambia dopo il primo salvataggio.', 'Shared by IT and EN. Fixed after the first save.')) ?></small></div><div><label for="date"><?= h(t('Data', 'Date')) ?></label><input id="date" type="date" name="date" value="<?= h($date) ?>" required><small class="subtle"><?= h(t('Data editoriale, senza programmazione.', 'Editorial date, without scheduling.')) ?></small></div></div>
<div class="tabs" role="tablist" aria-label="<?= h(t('Lingua del contenuto', 'Content language')) ?>"><?php foreach (SUPPORTED_LANGUAGES as $i => $lang): ?><button type="button" role="tab" id="tab-<?= h($lang) ?>" aria-controls="panel-<?= h($lang) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-tab="<?= h($lang) ?>"><?= $lang === 'it' ? 'Italiano' : 'English' ?></button><?php endforeach; ?></div>
<?php foreach ($documents as $lang => $doc): $meta = $doc['meta']; ?>
<section id="panel-<?= h($lang) ?>" class="language-panel" role="tabpanel" aria-labelledby="tab-<?= h($lang) ?>" data-panel="<?= h($lang) ?>">
<input type="hidden" name="translation[<?= h($lang) ?>][revision]" value="<?= h($doc['revision']) ?>">
<div class="title-status"><div><label for="title-<?= h($lang) ?>"><?= h(t('Titolo', 'Title')) ?> · <?= strtoupper($lang) ?></label><input class="title-input" id="title-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][title]" value="<?= h($meta['title'] ?? '') ?>" maxlength="250" placeholder="<?= h(t('Un titolo che merita di essere letto', 'A title worth reading')) ?>"></div><div><label for="status-<?= h($lang) ?>"><?= h(t('Visibilità', 'Visibility')) ?></label><select id="status-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][status]" <?= $kind === 'page' ? 'disabled' : '' ?>><?php foreach (['draft' => t('Bozza privata', 'Private draft'), 'published' => t('Pubblicato', 'Published')] as $status => $label): ?><option value="<?= $status ?>"<?= (($meta['status'] ?? ($doc['exists'] ? 'published' : 'draft')) === $status) ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div></div>
<div class="writing-toolbar"><div><button type="button" class="tool" data-format="bold" data-language="<?= h($lang) ?>" aria-label="<?= h(t('Grassetto', 'Bold')) ?>"><strong>B</strong></button><button type="button" class="tool" data-format="italic" data-language="<?= h($lang) ?>" aria-label="<?= h(t('Corsivo', 'Italic')) ?>"><em>I</em></button><button type="button" class="tool" data-format="heading" data-language="<?= h($lang) ?>">H2</button><button type="button" class="tool" data-format="link" data-language="<?= h($lang) ?>"><?= h(t('Link', 'Link')) ?></button><button type="button" class="tool" data-open-media="<?= h($lang) ?>">+ <?= h(t('Foto', 'Photo')) ?></button></div><button type="button" class="tool" data-preview="<?= h($lang) ?>"><?= h(t('Anteprima', 'Preview')) ?> ↗</button></div>
<label class="sr-only" for="body-<?= h($lang) ?>"><?= h(t('Testo Markdown', 'Markdown text')) ?></label><textarea class="markdown-editor" id="body-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][body]" rows="18" maxlength="500000" placeholder="<?= h(t('Scrivi qui. Basta un po’ di Markdown.', 'Write here. A little Markdown is all you need.')) ?>"><?= h($doc['body']) ?></textarea>
<div class="preview prose" data-preview-panel="<?= h($lang) ?>" hidden></div>
<div class="editor-details"><div><label for="excerpt-<?= h($lang) ?>"><?= h(t('Breve descrizione', 'Short description')) ?></label><textarea id="excerpt-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][excerpt]" rows="2" maxlength="500"><?= h($meta['excerpt'] ?? '') ?></textarea><label for="tags-<?= h($lang) ?>">Tag</label><input id="tags-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][tags]" value="<?= h(is_array($meta['tags'] ?? null) ? implode(', ', $meta['tags']) : ($meta['tags'] ?? '')) ?>" placeholder="<?= h(t('idee, tecnologia, storie', 'ideas, technology, stories')) ?>"></div><div><label for="cover-<?= h($lang) ?>"><?= h(t('Fotografia di copertina', 'Cover photograph')) ?></label><input id="cover-<?= h($lang) ?>" name="translation[<?= h($lang) ?>][cover]" value="<?= h($meta['cover'] ?? '') ?>" readonly><button type="button" class="secondary" data-open-media="<?= h($lang) ?>" data-cover-mode="1"><?= h(t('Scegli foto', 'Choose photo')) ?></button> <button type="button" class="quiet" data-clear-cover="<?= h($lang) ?>"><?= h(t('Rimuovi', 'Remove')) ?></button><p class="subtle"><small><?= h(t('Lascia vuota una lingua se non vuoi ancora tradurla.', 'Leave a language empty if you are not ready to translate it.')) ?></small></p></div></div>
<?php if ($doc['exists'] && ap_published($doc['meta'])): ?><a href="<?= h(language_url($lang, $kind === 'page' ? ($id === 'home' ? '/' : '/' . $id) : '/post/' . $id)) ?>" target="_blank" rel="noopener"><?= h(t('Apri versione pubblica', 'Open public version')) ?> · <?= strtoupper($lang) ?> ↗</a><?php endif; ?>
</section><?php endforeach; ?>
<details class="markdown-help"><summary><?= h(t('Markdown, in poche righe', 'Markdown, in a few lines')) ?></summary><div class="help-grid"><code>## <?= h(t('Sottotitolo', 'Heading')) ?><br>**<?= h(t('grassetto', 'bold')) ?>** · *<?= h(t('corsivo', 'italic')) ?>*<br>- <?= h(t('Elemento di una lista', 'List item')) ?><br>[<?= h(t('Testo', 'Text')) ?>](https://example.com)<br>![<?= h(t('Descrizione foto', 'Photo description')) ?>](@media/…)</code><p><?= h(t('Il titolo principale si inserisce nel campo Titolo. Per la foto, usa il pulsante + Foto. Puoi anche usare citazioni, codice e tabelle. Il codice HTML viene mostrato come testo.', 'Use the Title field for the main heading. Insert pictures with + Photo. Quotes, code and tables also work. HTML code is displayed as text.')) ?></p></div></details>
<div class="editor-bottom"><span class="subtle" data-save-status><?= h(t('Il salvataggio è manuale.', 'Saving is manual.')) ?></span><button type="submit"><?= h(t('Salva modifiche', 'Save changes')) ?></button></div></form>
<?php elseif ($page === 'pages'): ?>
<div class="page-heading"><div><p class="eyebrow"><?= h(t('LE BASI DEL TUO SITO', 'YOUR SITE’S FOUNDATIONS')) ?></p><h1><?= h(t('Le tue pagine', 'Your pages')) ?></h1></div></div>
<?php foreach (['home' => 'Home', 'skills' => t('Competenze', 'Skills'), 'about' => t('Chi sono e contatti', 'About & contact')] as $pageId => $label): ?><article class="post-row"><h2><?= h($label) ?></h2><a class="edit-link" href="<?= h(admin_url(['page' => 'edit', 'kind' => 'page', 'id' => $pageId])) ?>"><?= h(t('Modifica', 'Edit')) ?> →</a></article><?php endforeach; ?>
<?php elseif ($page === 'settings'): ?>
<div class="page-heading"><div><p class="eyebrow"><?= h(t('POCHE COSE, AL POSTO GIUSTO', 'A FEW THINGS, IN THE RIGHT PLACE')) ?></p><h1><?= h(t('Impostazioni', 'Settings')) ?></h1></div></div>
<nav class="settings-shortcuts" aria-label="<?= h(t('Sezioni delle impostazioni', 'Settings sections')) ?>"><a href="#site-settings"><?= h(t('Sito e stile', 'Site & style')) ?></a><a href="#backups">Backup / restore</a><a href="#check">Check</a><a href="#security"><?= h(t('Password', 'Password')) ?></a></nav>
<div id="site-settings"></div>
<?php require __DIR__ . '/settings.php'; ?>
<?php require __DIR__ . '/maintenance.php'; ?>
<div id="security"></div>
<section class="settings-card"><h2><?= h(t('Cambia password', 'Change password')) ?></h2><p class="subtle"><?= h(t('Usa da 12 a 72 caratteri ASCII. Dopo il cambio dovrai accedere di nuovo.', 'Use 12–72 ASCII characters. You will sign in again after changing it.')) ?></p><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="password"><label for="current-password"><?= h(t('Password attuale', 'Current password')) ?></label><input id="current-password" name="current_password" type="password" autocomplete="current-password" required><label for="new-password"><?= h(t('Nuova password', 'New password')) ?></label><input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required><label for="confirm-password"><?= h(t('Ripeti la nuova password', 'Repeat new password')) ?></label><input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required><button type="submit"><?= h(t('Aggiorna password', 'Update password')) ?></button></form></section>
<section class="settings-card"><h2><?= h(t('Manutenzione', 'Maintenance')) ?></h2><p class="subtle"><?= h(t('La cache si aggiorna da sola. Puoi svuotarla anche qui.', 'The cache updates automatically. You can also clear it here.')) ?></p><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="cache"><button class="secondary" type="submit"><?= h(t('Svuota cache', 'Clear cache')) ?></button></form></section>
<?php elseif ($page === 'media'): ?>
<div class="page-heading"><div><p class="eyebrow"><?= h(t('IMMAGINI CHE ACCOMPAGNANO LE PAROLE', 'IMAGES THAT ACCOMPANY YOUR WORDS')) ?></p><h1><?= h(t('Le tue fotografie', 'Your photographs')) ?></h1></div><button type="button" data-open-media="library">+ <?= h(t('Carica foto', 'Upload photo')) ?></button></div>
<p class="subtle"><?= h(t('JPG, PNG o WebP. Le foto caricate sono pubbliche tramite il loro indirizzo.', 'JPG, PNG or WebP. Uploaded photos are public at their addresses.')) ?></p><div class="media-grid" data-library>
<?php foreach (ap_media_files() as $file): ?><figure><img loading="lazy" src="<?= h(ap_url('/media/' . basename($file))) ?>" alt=""><figcaption><a href="<?= h(ap_url('/media/' . basename($file))) ?>" target="_blank" rel="noopener"><?= h(t('Apri foto', 'Open photo')) ?> ↗</a></figcaption></figure><?php endforeach; ?></div>
<?php else: http_response_code(404); ?><h1>404</h1><?php endif; ?>
</main></div>
<?php if ($authenticated): ?>
<dialog id="media-dialog"><div class="dialog-top"><h2><?= h(t('Una foto, una storia.', 'A photo, a story.')) ?></h2><button type="button" class="quiet" data-close-dialog aria-label="<?= h(t('Chiudi', 'Close')) ?>">×</button></div><p class="subtle"><?= h(t('JPG, PNG o WebP · massimo ', 'JPG, PNG or WebP · up to ')) ?><?= (int) ap_config('upload_max_mb') ?> MB · 24 MP</p><form data-upload><label for="photo"><?= h(t('Carica dal dispositivo', 'Upload from your device')) ?></label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required><button type="submit"><?= h(t('Carica foto', 'Upload photo')) ?></button></form><label for="photo-alt"><?= h(t('Descrivi la foto (testo alternativo)', 'Describe the photo (alternative text)')) ?></label><input id="photo-alt" placeholder="<?= h(t('Cosa si vede nella fotografia?', 'What is in the photograph?')) ?>"><p role="status" data-upload-status></p><div class="media-grid" data-picker>
<?php foreach (ap_media_files() as $file): ?><button type="button" class="photo-choice" data-photo="@media/<?= h(basename($file)) ?>" aria-label="<?= h(t('Usa questa foto', 'Use this photo')) ?>"><img loading="lazy" src="<?= h(ap_url('/media/' . basename($file))) ?>" alt=""></button><?php endforeach; ?></div></dialog>
<?php endif; ?>
<footer class="app-width app-footer">AlternPress 0.5.0-alpha · <?= h(t('Semplice. Autosufficiente. Tuo.', 'Simple. Self-contained. Yours.')) ?></footer>
</body></html>
