<?php
declare(strict_types=1);
if (!defined('AP_ROOT')) { http_response_code(404); exit; }

function ap_maintenance_redirect(string $section, string $message = ''): never {
    if ($message !== '') $_SESSION['flash'] = $message;
    header('Location: ' . admin_url(['page' => 'settings']) . '#' . $section, true, 303); exit;
}
function ap_admin_download_backup(string $name): never {
    if (!ap_logged_in()) { http_response_code(401); throw new RuntimeException(t('Accedi per continuare.', 'Sign in to continue.')); }
    $path = ap_backup_path($name);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    session_write_close();
    readfile($path); exit;
}
function ap_admin_maintenance_action(string $action): void {
    if (!in_array($action, ['backup_create', 'restore_prepare', 'restore_apply', 'restore_cancel', 'check'], true)) return;
    if (!ap_logged_in()) { http_response_code(401); throw new RuntimeException(t('Accedi per continuare.', 'Sign in to continue.')); }
    ap_check_csrf();
    if ($action === 'backup_create') {
        $backup = ap_backup_create();
        ap_maintenance_redirect('backups', t('Backup creato. Puoi scaricarlo qui sotto.', 'Backup created. Download it below.'));
    }
    if ($action === 'restore_prepare') {
        $file = $_FILES['archive'] ?? [];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('Caricamento non riuscito. Scegli un backup dati .tar.gz entro il limite indicato.', 'Upload failed. Choose a data backup .tar.gz within the displayed limit.'));
        if (is_string($_SESSION['restore_token'] ?? null) && preg_match('/\A[a-f0-9]{48}\z/D', $_SESSION['restore_token'])) ap_restore_cancel($_SESSION['restore_token']);
        unset($_SESSION['restore_token']);
        $preview = ap_restore_prepare($file['tmp_name']);
        $_SESSION['restore_token'] = $preview['token'];
        ap_maintenance_redirect('restore-preview');
    }
    if ($action === 'restore_apply' || $action === 'restore_cancel') {
        $token = $_POST['restore_token'] ?? '';
        if (!is_string($token) || !is_string($_SESSION['restore_token'] ?? null) || !hash_equals($_SESSION['restore_token'], $token)) throw new RuntimeException(t('Anteprima scaduta. Carica di nuovo il backup.', 'Preview expired. Upload the backup again.'));
        if ($action === 'restore_cancel') {
            ap_restore_cancel($token); unset($_SESSION['restore_token']);
            ap_maintenance_redirect('backups', t('Ripristino annullato.', 'Restore cancelled.'));
        }
        if (($_POST['confirm_restore'] ?? '') !== '1') throw new RuntimeException(t('Conferma la sostituzione dei dati per continuare.', 'Confirm data replacement to continue.'));
        $result = ap_restore_apply($token);
        unset($_SESSION['restore_token'], $_SESSION['diagnostics']);
        ap_maintenance_redirect('backups', t('Ripristino completato. Il backup prima del ripristino è disponibile nell’elenco.', 'Restore completed. The backup made before restoring is available in the list.'));
    }
    if ($action === 'check') {
        $report = ap_diagnostics();
        // Keep the session bounded; the scan totals still describe the entire report.
        $report['issues'] = array_slice($report['issues'], 0, 200);
        $report['orphans'] = array_slice($report['orphans'], 0, 200);
        $report['permissions'] = array_slice(array_values(array_filter($report['permissions'], static fn($row) => in_array($row['type'], ['directory', 'dir'], true))), 0, 500);
        $_SESSION['diagnostics'] = $report;
        ap_maintenance_redirect('check');
    }
}
function ap_upload_limit(): int {
    $bytes = static function(string $value): int {
        $value = trim($value); $number = (float) $value;
        return (int) ($number * match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
    };
    $upload = $bytes(ini_get('upload_max_filesize'));
    $post = $bytes(ini_get('post_max_size'));
    return min(64 * 1048576, $upload > 0 ? $upload : PHP_INT_MAX, $post > 0 ? max(0, $post - 1048576) : PHP_INT_MAX);
}
