<?php
declare(strict_types=1);
require_once __DIR__ . '/settings.php';

function ap_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('alternpress_' . substr(hash('sha256', AP_ROOT . ap_base()), 0, 12));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params(['lifetime' => 0, 'path' => ap_url('/admin/'), 'secure' => ap_https(), 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) throw new RuntimeException('Cannot start a secure session.');
    }
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: http:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    if (!is_string($_SESSION['csrf'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function ap_csrf(): string { return is_string($_SESSION['csrf'] ?? null) ? $_SESSION['csrf'] : ''; }
function ap_check_csrf(): void {
    if (!preg_match('/\A[a-f0-9]{64}\z/D', ap_csrf()) || !is_string($_POST['csrf'] ?? null) || !hash_equals(ap_csrf(), $_POST['csrf'])) {
        http_response_code(403);
        throw new RuntimeException(t('Sessione scaduta. Ricarica la pagina e riprova.', 'Session expired. Reload this page and try again.'));
    }
}
/** Missing or corrupt credentials never authenticate an existing session. */
function ap_auth_data(): array {
    try { $auth = ap_private_read('auth'); }
    catch (Throwable) { error_log('AlternPress: cannot read administrator credentials.'); return []; }
    if (!is_string($auth['hash'] ?? null) || !is_string($auth['version'] ?? null)
        || !preg_match('/\A[a-f0-9]{48}\z/D', $auth['version']) || password_get_info($auth['hash'])['algo'] === null) return [];
    return $auth;
}
function ap_logged_in(): bool {
    $auth = ap_auth_data(); $now = time();
    $valid = isset($auth['version']) && is_string($_SESSION['auth_version'] ?? null) && is_int($_SESSION['last_seen'] ?? null)
        && hash_equals($auth['version'], $_SESSION['auth_version'])
        && $_SESSION['last_seen'] <= $now && $_SESSION['last_seen'] > 0
        && $now - $_SESSION['last_seen'] < max(1, (int) ap_config('session_hours')) * 3600;
    if ($valid) $_SESSION['last_seen'] = time();
    else unset($_SESSION['auth_version'], $_SESSION['last_seen']);
    return $valid;
}
function ap_login(string $password): bool {
    return ap_lock(static function() use ($password): bool {
        $attempts = ap_private_read('attempts');
        $now = time();
        $attempts = array_filter($attempts, static fn($row) => is_array($row) && is_int($row['until'] ?? null) && is_int($row['count'] ?? null) && $row['count'] >= 0 && $row['until'] > $now);
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
        $row = $attempts[$key] ?? ['count' => 0, 'until' => $now + 900];
        if ($row['count'] >= 5) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $row['until'] - $now));
            throw new RuntimeException(t('Troppi tentativi. Riprova tra 15 minuti.', 'Too many attempts. Try again in 15 minutes.'));
        }
        $auth = ap_auth_data();
        if (strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, "\0") && isset($auth['hash']) && password_verify($password, $auth['hash'])) {
            unset($attempts[$key]);
            ap_private_write('attempts', $attempts);
            if (!session_regenerate_id(true)) throw new RuntimeException('Cannot renew the secure session.');
            $_SESSION = [];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['auth_version'] = $auth['version'];
            $_SESSION['last_seen'] = $now;
            return true;
        }
        $row['count']++;
        $attempts[$key] = $row;
        ap_private_write('attempts', array_slice($attempts, -1000, null, true));
        return false;
    });
}
function ap_set_password(string $password): void {
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) throw new RuntimeException('Password: 12–72 bytes, without null characters.');
    ap_private_write('auth', ['hash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(24))]);
}
function ap_logout(): void {
    $_SESSION = [];
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'], 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    session_destroy();
}
function ap_input_string(array $input, string $key, string $default = ''): string {
    if (!array_key_exists($key, $input)) return $default;
    if (!is_string($input[$key])) throw new RuntimeException(t('Formato dei dati non valido.', 'Invalid input format.'));
    return $input[$key];
}
/** Validate before retaining failed form input for redisplay. */
function ap_validate_document_input(array $input): void {
    foreach (['id', 'kind', 'date'] as $key) ap_input_string($input, $key);
    if (!is_array($input['translation'] ?? null)) throw new RuntimeException('Invalid translation.');
    foreach (SUPPORTED_LANGUAGES as $language) {
        $fields = $input['translation'][$language] ?? [];
        if (!is_array($fields)) throw new RuntimeException('Invalid translation.');
        foreach (['title', 'body', 'status', 'revision', 'cover', 'tags', 'excerpt'] as $key) ap_input_string($fields, $key);
    }
}
function ap_post_path(string $language, string $id, string $kind = 'post'): string {
    if (!in_array($language, SUPPORTED_LANGUAGES, true) || !ap_id_valid($id) || !in_array($kind, ['post', 'page'], true)) throw new RuntimeException('Invalid content path.');
    if ($kind === 'page' && !in_array($id, ['home', 'about', 'skills'], true)) throw new RuntimeException('Invalid page.');
    $path = AP_ROOT . '/content/' . $language . '/' . ($kind === 'post' ? 'post/' : '') . $id . '/index.md';
    ap_assert_path($path);
    return $path;
}
function ap_document(string $language, string $id, string $kind = 'post'): array {
    $path = ap_post_path($language, $id, $kind);
    $raw = is_file($path) ? file_get_contents($path) : '';
    if ($raw === false) throw new RuntimeException('Cannot read this document.');
    [$meta, $body] = parse_front_matter((string) $raw);
    return ['meta' => $meta, 'body' => $body, 'revision' => hash('sha256', (string) $raw), 'exists' => is_file($path)];
}
function ap_all_posts(): array {
    $rows = [];
    foreach (SUPPORTED_LANGUAGES as $language) {
        foreach (glob(AP_ROOT . '/content/' . $language . '/post/*/index.md') ?: [] as $file) {
            try { ap_assert_path($file); } catch (RuntimeException) { continue; }
            $id = basename(dirname($file));
            if (!ap_id_valid($id)) continue;
            $document = ap_document($language, $id);
            $rows[$id]['id'] = $id;
            $rows[$id]['date'] = $document['meta']['date'] ?? substr($id, 0, 10);
            $rows[$id]['translations'][$language] = $document;
        }
    }
    uasort($rows, static fn($a, $b) => strcmp($b['date'] . $b['id'], $a['date'] . $a['id']));
    return $rows;
}
function ap_clean_line(string $value, int $max = 250): string {
    if (!mb_check_encoding($value, 'UTF-8')) throw new RuntimeException(t('Codifica del testo non valida.', 'Invalid text encoding.'));
    $value = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value) ?? '');
    if (mb_strlen($value) > $max) throw new RuntimeException(t('Un campo è troppo lungo.', 'A field is too long.'));
    return $value;
}
function ap_save_document(array $input): string {
    ap_validate_document_input($input);
    $savedId = ap_lock(static function() use ($input): string {
        $kind = ($input['kind'] ?? '') === 'page' ? 'page' : 'post';
        $id = strtolower(trim((string) ($input['id'] ?? '')));
        if (!ap_id_valid($id)) throw new RuntimeException(t('Usa un identificativo con lettere minuscole, numeri e trattini.', 'Use a slug with lowercase letters, numbers and hyphens.'));
        $date = (string) ($input['date'] ?? date('Y-m-d'));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException(t('Data non valida.', 'Invalid date.'));
        $writes = [];
        $originals = [];
        $hasTitle = false;
        foreach (SUPPORTED_LANGUAGES as $language) {
            $fields = $input['translation'][$language] ?? [];
            if (!is_array($fields)) throw new RuntimeException('Invalid translation.');
            $doc = ap_document($language, $id, $kind);
            $title = ap_clean_line((string) ($fields['title'] ?? ''));
            $body = str_replace(["\r\n", "\r"], "\n", (string) ($fields['body'] ?? ''));
            $status = (string) ($fields['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'published'], true)) throw new RuntimeException('Invalid status.');
            if ($title === '' && !$doc['exists'] && trim($body) === '') continue;
            if ($title === '') throw new RuntimeException(t('Inserisci il titolo per ogni traduzione compilata.', 'Enter a title for each translation you use.'));
            if ($status === 'published' && trim($body) === '') throw new RuntimeException(t('Aggiungi il testo prima di pubblicare.', 'Add some text before publishing.'));
            if (strlen($body) > 500000 || !mb_check_encoding($body, 'UTF-8')) throw new RuntimeException(t('Testo troppo lungo o codifica non valida.', 'Text is too long or has invalid encoding.'));
            if (!hash_equals($doc['revision'], (string) ($fields['revision'] ?? ''))) {
                http_response_code(409);
                throw new RuntimeException(t('Il contenuto è cambiato in un’altra scheda, oppure questo identificativo esiste già. Copia il testo e ricarica prima di salvare.', 'Content changed in another tab, or this slug already exists. Copy your text and reload before saving.'));
            }
            $cover = ap_clean_line((string) ($fields['cover'] ?? ''));
            if ($cover !== '' && !preg_match('/\A@media\/[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $cover) && $cover !== ($doc['meta']['cover'] ?? '')) throw new RuntimeException(t('Scegli la copertina dalla libreria foto.', 'Choose a cover from the photo library.'));
            $meta = $doc['meta'];
            $meta['title'] = $title;
            $meta['date'] = $date;
            $meta['status'] = $kind === 'page' ? 'published' : $status;
            $meta['tags'] = normalize_tags(ap_clean_line((string) ($fields['tags'] ?? ''), 500));
            $meta['excerpt'] = ap_clean_line((string) ($fields['excerpt'] ?? ''), 500);
            $meta['cover'] = $cover;
            $front = "---\n";
            foreach ($meta as $key => $value) {
                if (!preg_match('/\A[a-z_]+\z/', (string) $key)) continue;
                $front .= $key . ': ' . ($key === 'tags' ? '[' . implode(', ', $value) . ']' : json_encode(ap_clean_line((string) $value, 1000), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n";
            }
            $path = ap_post_path($language, $id, $kind);
            $writes[$path] = $front . "---\n\n" . trim($body) . "\n";
            $originals[$path] = $doc['exists'] ? file_get_contents($path) : null;
            $hasTitle = true;
        }
        if (!$hasTitle) throw new RuntimeException(t('Scrivi almeno una versione dell’articolo.', 'Write at least one version of the article.'));
        $backup = [];
        foreach ($originals as $path => $raw) if ($raw !== null) $backup[substr($path, strlen(AP_ROOT) + 1)] = $raw;
        if ($backup) ap_private_write('history/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)), $backup);
        $completed = [];
        try {
            foreach ($writes as $path => $raw) { ap_atomic_write($path, $raw, 0644); $completed[] = $path; }
        } catch (Throwable $error) {
            foreach ($completed as $path) {
                if ($originals[$path] === null) unlink($path); else ap_atomic_write($path, $originals[$path], 0644);
            }
            throw $error;
        }
        ap_clear_post_cache();
        return $id;
    });
    ap_page_cache_warm_document($savedId, ($input['kind'] ?? '') === 'page' ? 'page' : 'post');
    return $savedId;
}
function ap_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) throw new RuntimeException(t('Caricamento non riuscito. Controlla il limite della foto.', 'Upload failed. Check the photo size limit.'));
    $path = $file['tmp_name'];
    if (!is_uploaded_file($path)) throw new RuntimeException(t('Caricamento non valido.', 'Invalid upload.'));
    $bytes = filesize($path);
    if ($bytes === false || $bytes < 1 || $bytes > max(1, (int) ap_config('upload_max_mb')) * 1024 * 1024) throw new RuntimeException(t('Foto troppo grande.', 'Photo is too large.'));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $size = @getimagesize($path);
    if (!isset($extensions[$mime]) || !$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > (int) ap_config('image_max_pixels')) throw new RuntimeException(t('Usa una vera foto JPG, PNG o WebP, fino a 24 megapixel.', 'Use a real JPG, PNG or WebP photo, up to 24 megapixels.'));
    $image = @imagecreatefromstring(file_get_contents($path));
    if (!$image) throw new RuntimeException(t('Immagine non leggibile.', 'Cannot read this image.'));
    try {
    // Re-encoding strips executable payloads and EXIF/GPS metadata.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if ($angle !== 0) { $rotated = imagerotate($image, $angle, 0); if (!$rotated) throw new RuntimeException('Cannot rotate image.'); imagedestroy($image); $image = $rotated; }
    }
    $limit = max(1, (int) ap_config('image_max_width'));
    if (max(imagesx($image), imagesy($image)) > $limit) {
        $ratio = $limit / max(imagesx($image), imagesy($image));
        $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
        if (!$scaled) throw new RuntimeException('Cannot resize image.');
        imagedestroy($image); $image = $scaled;
    }
    imagealphablending($image, false); imagesavealpha($image, true);
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    // Finish encoding before taking the shared write lock; restore sees either a
    // complete photo or no photo, never a partially written file.
    ob_start();
    try {
        $ok = match ($mime) { 'image/jpeg' => imagejpeg($image, null, 88), 'image/png' => imagepng($image, null, 6), 'image/webp' => imagewebp($image, null, 88) };
        $encoded = ob_get_contents();
    } finally { ob_end_clean(); }
    if (!$ok || !is_string($encoded) || $encoded === '') throw new RuntimeException('Cannot encode image.');
    ap_lock(static fn() => ap_atomic_write(AP_ROOT . '/media/' . $name, $encoded, 0644));
    return ['name' => $name, 'url' => ap_url('/media/' . $name), 'reference' => '@media/' . $name];
    } finally { imagedestroy($image); }
}
function ap_clear_post_cache(): void {
    ap_assert_path(AP_ROOT . '/cache');
    foreach (glob(AP_ROOT . '/cache/posts-*.json') ?: [] as $cache) {
        ap_assert_path($cache);
        if (is_file($cache) && !unlink($cache)) throw new RuntimeException('Cannot clear the cache.');
    }
    ap_page_cache_clear();
}
function ap_media_files(): array {
    ap_assert_path(AP_ROOT . '/media');
    return array_values(array_filter(array_reverse(glob(AP_ROOT . '/media/*.{jpg,png,webp}', GLOB_BRACE) ?: []), static function(string $file): bool {
        try { ap_assert_path($file); return is_file($file); } catch (RuntimeException) { return false; }
    }));
}
