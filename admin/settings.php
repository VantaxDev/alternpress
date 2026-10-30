<?php
declare(strict_types=1);
if (!defined('AP_ROOT') || !ap_logged_in()) { http_response_code(404); exit; }
$settingsFailed = $action === 'settings' && $error !== '';
function settings_value(string $key, ?string $language = null): string {
    global $settingsFailed;
    if ($key === 'default_language' && ap_config('default_language_locked')) return DEFAULT_LANGUAGE;
    $value = $settingsFailed ? ($_POST[$key] ?? '') : ap_config($key);
    if ($language !== null) $value = is_array($value) ? ($value[$language] ?? '') : '';
    return is_scalar($value) ? (string) $value : '';
}
$settingsRevision = $settingsFailed && is_string($_POST['settings_revision'] ?? null) ? $_POST['settings_revision'] : ap_settings_revision();
$indexing = $settingsFailed ? ($_POST['search_indexing'] ?? '') === '1' : (bool) ap_config('search_indexing');
?>
<form method="post" action="<?= h(admin_url(['page' => 'settings'])) ?>" class="site-settings" data-settings-form>
<?php csrf_field(); ?><input type="hidden" name="action" value="settings"><input type="hidden" name="settings_revision" value="<?= h($settingsRevision) ?>">
<section class="settings-card">
<div class="settings-heading"><div><p class="eyebrow"><?= h(t('IDENTITÀ', 'IDENTITY')) ?></p><h2><?= h(t('Il tuo sito', 'Your site')) ?></h2></div><?php if (route_content_exists($CONTENT_ROOT, $ui, '/')): ?><a href="<?= h(language_url($ui)) ?>" target="_blank" rel="noopener"><?= h(t('Apri sito', 'View site')) ?> ↗</a><?php endif; ?></div>
<div class="settings-grid"><div><label for="site-name"><?= h(t('Nome del sito', 'Site name')) ?></label><input id="site-name" name="name" value="<?= h(settings_value('name')) ?>" maxlength="120" required></div><div><label for="logo-mark"><?= h(t('Simbolo del logo', 'Logo symbol')) ?></label><input id="logo-mark" name="logo_mark" value="<?= h(settings_value('logo_mark')) ?>" maxlength="12"></div></div>
<div class="settings-grid"><?php foreach (SUPPORTED_LANGUAGES as $lang): ?><div><label for="description-<?= h($lang) ?>"><?= h(t('Descrizione del sito', 'Site description')) ?> · <?= strtoupper($lang) ?></label><textarea id="description-<?= h($lang) ?>" name="description[<?= h($lang) ?>]" rows="3" maxlength="500"><?= h(settings_value('description', $lang)) ?></textarea></div><?php endforeach; ?></div>
<p class="subtle settings-help"><?= h(t('Il nome appare nel logo e nei titoli delle pagine. Le descrizioni presentano il sito ai motori di ricerca.', 'The name appears in the logo and page titles. Descriptions introduce the site to search engines.')) ?></p>
<div class="settings-grid"><div><label for="default-language"><?= h(t('Lingua predefinita', 'Default language')) ?></label><select id="default-language" name="default_language"<?= ap_config('default_language_locked') ? ' disabled' : '' ?>><?php foreach (SUPPORTED_LANGUAGES as $lang): ?><option value="<?= h($lang) ?>"<?= settings_value('default_language') === $lang ? ' selected' : '' ?>><?= h(ap_language_name($lang)) ?></option><?php endforeach; ?></select><?php if (ap_config('default_language_locked')): ?><input type="hidden" name="default_language" value="<?= h(DEFAULT_LANGUAGE) ?>"><small>config.local.php</small><?php endif; ?></div><div><label for="posts-per-page"><?= h(t('Articoli in home', 'Articles on the home page')) ?></label><input id="posts-per-page" name="posts_per_page" type="number" min="1" max="100" value="<?= h(settings_value('posts_per_page')) ?>" required></div></div>
</section>
<section class="settings-card"><p class="eyebrow"><?= h(t('INDIRIZZO E RICERCA', 'ADDRESS & SEARCH')) ?></p><h2><?= h(t('Dove trovarti', 'Where to find you')) ?></h2>
<label for="site-url"><?= h(t('URL pubblico del sito', 'Public site URL')) ?></label><input id="site-url" name="site_url" type="url" value="<?= h(settings_value('site_url')) ?>" maxlength="500" placeholder="<?= h(ap_origin() . ap_base()) ?>">
<p class="subtle settings-help"><?= h(t('Lascia vuoto per riconoscere automaticamente dominio e cartella. Se lo imposti, includi l’eventuale sottocartella: viene usato per gli indirizzi canonici delle pagine e non sposta il sito né il pannello.', 'Leave empty to detect the domain and folder automatically. If set, include any subfolder: this controls canonical page addresses and does not move the site or dashboard.')) ?></p>
<label class="checkbox-label" for="search-indexing"><input id="search-indexing" type="checkbox" name="search_indexing" value="1"<?= $indexing ? ' checked' : '' ?>><?= h(t('Consenti l’indicizzazione sui motori di ricerca', 'Allow search engine indexing')) ?></label>
<p class="subtle settings-help"><?= h(t('Per la versione alpha puoi lasciarla disattivata.', 'You can leave this disabled for the alpha site.')) ?></p></section>
<section class="settings-card"><p class="eyebrow"><?= h(t('A FONDO PAGINA', 'AT THE FOOT OF THE PAGE')) ?></p><h2>Footer</h2>
<div class="settings-grid"><?php foreach (SUPPORTED_LANGUAGES as $lang): ?><div><label for="footer-<?= h($lang) ?>"><?= h(t('Testo footer', 'Footer text')) ?> · <?= strtoupper($lang) ?></label><textarea id="footer-<?= h($lang) ?>" name="tagline[<?= h($lang) ?>]" rows="3" maxlength="1000"><?= h(settings_value('tagline', $lang)) ?></textarea></div><?php endforeach; ?></div>
<label for="legal-text"><?= h(t('Intestazione / dati legali', 'Credits / legal details')) ?></label><input id="legal-text" name="legal" value="<?= h(settings_value('legal')) ?>" maxlength="1000">
<label for="site-email"><?= h(t('Email di contatto', 'Contact email')) ?></label><input id="site-email" name="email" type="email" value="<?= h(settings_value('email')) ?>" maxlength="254">
<p class="subtle settings-help"><?= h(t('Il footer usa testo semplice. L’anno si aggiorna automaticamente; l’email resta protetta come prima.', 'The footer uses plain text. The year updates automatically; the email remains protected.')) ?></p></section>
<section class="settings-card"><p class="eyebrow"><?= h(t('ASPETTO', 'APPEARANCE')) ?></p><h2><?= h(t('CSS personalizzato', 'Custom CSS')) ?></h2>
<p class="subtle"><?= h(t('Aggiungi le tue regole sopra lo stile del blog: colori, caratteri, spaziature e disposizione. Il pannello mantiene sempre il suo stile.', 'Override the blog’s styles: colors, fonts, spacing and layout. The dashboard always keeps its own styling.')) ?></p>
<label for="custom-css" class="sr-only"><?= h(t('CSS personalizzato', 'Custom CSS')) ?></label><textarea id="custom-css" name="custom_css" class="css-editor" rows="14" maxlength="100000" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder=":root {&#10;  --pico-primary: #365d46;&#10;}&#10;&#10;.footer {&#10;  text-align: center;&#10;}"><?= h(settings_value('custom_css')) ?></textarea>
<div class="settings-css-tools"><button class="secondary" type="button" data-reset-css><?= h(t('Ripristina stile originale', 'Restore original style')) ?></button><small class="subtle"><?= h(t('Svuota il CSS: premi Salva per applicare il ripristino.', 'Clears the CSS: press Save to apply the reset.')) ?></small></div>
<p class="subtle settings-help"><?= h(t('Solo CSS e risorse locali, senza @import o servizi esterni. Le personalizzazioni restano conservate quando aggiorni il programma.', 'CSS and local resources only, without @import or external services. Customizations survive software updates.')) ?></p></section>
<div class="settings-save"><span class="subtle" data-settings-status><?= h(t('Le modifiche si applicano quando salvi.', 'Changes take effect when you save.')) ?></span><button type="submit"><?= h(t('Salva impostazioni', 'Save settings')) ?></button></div>
</form>
