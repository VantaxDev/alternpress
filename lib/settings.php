<?php
declare(strict_types=1);

function ap_settings_revision(): string {
    $file = AP_STORAGE . '/settings.php';
    ap_assert_path($file, AP_STORAGE);
    return is_file($file) ? hash_file('sha256', $file) : hash('sha256', '');
}
function ap_setting_text(array $input, string $key, int $limit): string {
    if (!is_string($input[$key] ?? null) || !mb_check_encoding($input[$key], 'UTF-8')) throw new RuntimeException(t('Campo non valido: ', 'Invalid field: ') . $key);
    return ap_clean_line($input[$key], $limit);
}
function ap_validate_css(string $css): string {
    $css = str_replace(["\r\n", "\r"], "\n", $css);
    if (strlen($css) > 100000 || !mb_check_encoding($css, 'UTF-8') || str_contains($css, "\0")) throw new RuntimeException(t('Il CSS deve essere testo UTF-8, massimo 100 KB.', 'CSS must be UTF-8 text, up to 100 KB.'));
    // Decode CSS escapes for resource checks without altering the saved source.
    $scan = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    $scan = preg_replace('/\\\\\n/', '', $scan) ?? $scan;
    $scan = preg_replace_callback('/\\\\([0-9a-f]{1,6})[ \t\r\n\f]?|\\\\([^\r\n])/i', static function($m): string {
        if (($m[1] ?? '') === '') return $m[2];
        $point = hexdec($m[1]);
        return $point < 1 || $point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff) ? "\u{FFFD}" : mb_chr($point, 'UTF-8');
    }, $scan) ?? $scan;
    if (preg_match('~@import\b|(?:https?|ftp|data|javascript):|//|expression\s*\(~i', $scan)) {
        throw new RuntimeException(t('Usa solo CSS e risorse locali: niente @import, URL esterni o script.', 'Use CSS and local resources only: no @import, external URLs or scripts.'));
    }
    return trim($css);
}
function ap_save_settings(array $input, string $section): void {
    ap_lock(static function() use ($input, $section): void {
        if (!is_string($input['settings_revision'] ?? null) || !hash_equals(ap_settings_revision(), $input['settings_revision'])) {
            http_response_code(409);
            throw new RuntimeException(t('Le impostazioni sono cambiate in un’altra scheda. Copia le modifiche e ricarica prima di salvare.', 'Settings changed in another tab. Copy your changes and reload before saving.'));
        }
        $old = ap_private_read('settings');
        $values = $old['values'] ?? [];
        if ($section === 'site') {
            $updates = [];
            foreach (['name' => 120, 'logo_mark' => 12, 'email' => 254, 'legal' => 1000, 'site_url' => 500, 'default_language' => 2] as $key => $limit) $updates[$key] = ap_setting_text($input, $key, $limit);
            if ($updates['name'] === '') throw new RuntimeException(t('Inserisci il nome del sito.', 'Enter a site name.'));
            if ($updates['email'] !== '' && !filter_var($updates['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('Indirizzo email non valido.', 'Invalid email address.'));
            if (!in_array($updates['default_language'], SUPPORTED_LANGUAGES, true)) throw new RuntimeException(t('Lingua non valida.', 'Invalid language.'));
            $url = rtrim($updates['site_url'], '/');
            if ($url !== '') {
                $parts = parse_url($url);
                if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException(t('Usa un URL completo http/https, senza credenziali, query o frammenti.', 'Use a complete http/https URL without credentials, query strings or fragments.'));
            }
            $updates['site_url'] = $url;
            $count = filter_var($input['posts_per_page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($count === false) throw new RuntimeException(t('Mostra da 1 a 100 articoli in home.', 'Display 1 to 100 articles on the home page.'));
            $updates['posts_per_page'] = $count;
            $updates['search_indexing'] = ($input['search_indexing'] ?? '') === '1';
            foreach (['description' => 500, 'tagline' => 1000] as $key => $limit) {
                if (!is_array($input[$key] ?? null)) throw new RuntimeException(t('Compila i campi per entrambe le lingue.', 'Complete the fields for both languages.'));
                foreach (SUPPORTED_LANGUAGES as $language) $updates[$key][$language] = ap_setting_text($input[$key], $language, $limit);
            }
            if (!is_string($input['custom_css'] ?? null)) throw new RuntimeException('Invalid CSS.');
            $updates['custom_css'] = ap_validate_css($input['custom_css']);
        } elseif ($section === 'css' || $section === 'css_reset') {
            if ($section === 'css' && !is_string($input['custom_css'] ?? null)) throw new RuntimeException('Invalid CSS.');
            $updates = ['custom_css' => $section === 'css_reset' ? '' : ap_validate_css($input['custom_css'])];
        } else { throw new RuntimeException('Unknown settings section.'); }
        if ($old) ap_private_write('history/settings-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)), $old);
        ap_private_write('settings', ['values' => array_replace($values, $updates)]);
    });
}
