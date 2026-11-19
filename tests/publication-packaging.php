<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bin/update.php';
$source = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/ap-publication-pack-' . bin2hex(random_bytes(6));
$checks = 0;
function pack_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function pack_remove(string $path): void {
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') pack_remove($path . '/' . $name);
    rmdir($path);
}
mkdir($temp, 0700);
try {
    // Neither loading order may redeclare the extracted publication functions.
    foreach ([['lib/diagnostics.php', 'index.php'], ['index.php', 'lib/diagnostics.php']] as $order) {
        $code = "define('AP_LIBRARY', true);";
        foreach ($order as $file) $code .= 'require_once ' . var_export($source . '/' . $file, true) . ';';
        $code .= "require_once " . var_export($source . '/lib/publication.php', true) . ";exit(ap_publication_state(['publish_at'=>'invalid']) === 'invalid' ? 0 : 1);";
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);
        pack_check($status === 0, 'Bootstrap/diagnostics load order: ' . implode("\n", $output));
    }
    $release = $temp . '/release';
    mkdir($release);
    foreach (APCodeUpdater::REQUIRED as $relative) {
        if (!is_dir(dirname($release . '/' . $relative))) mkdir(dirname($release . '/' . $relative), 0700, true);
        copy($source . '/' . $relative, $release . '/' . $relative);
    }
    $archive = $temp . '/good.ap.gz';
    $sha = APCodeUpdater::pack($release, $archive);
    $data = json_decode(gzdecode(file_get_contents($archive)), true, 512, JSON_THROW_ON_ERROR);
    pack_check(base64_decode($data['files']['lib/publication.php'], true) === file_get_contents($source . '/lib/publication.php'), 'Package retains exact publication module.');
    $class = new ReflectionClass(APCodeUpdater::class);
    $updater = $class->newInstanceWithoutConstructor();
    $reader = $class->getMethod('package');
    $reader->setAccessible(true);
    [$id, $files] = $reader->invoke($updater, $archive, $sha);
    pack_check(isset($files['lib/publication.php']), 'Offline validator accepts complete package.');
    // Missing source module must prevent package construction.
    unlink($release . '/lib/publication.php');
    try {
        APCodeUpdater::pack($release, $temp . '/missing-source.ap.gz');
        throw new LogicException('Missing publication source accepted.');
    } catch (RuntimeException $error) {
        pack_check($error->getMessage() === 'Missing/oversized code file.', 'Missing required source rejected.');
    }
    // Even a self-consistent identity cannot authorize a package without it.
    unset($files['lib/publication.php']);
    $data['id'] = APCodeUpdater::identity($files);
    $files['lib/update-release.php'] = "<?php\nreturn '" . $data['id'] . "';\n";
    $data['files'] = array_map('base64_encode', $files);
    $bad = $temp . '/missing-module.ap.gz';
    file_put_contents($bad, gzencode(json_encode($data, JSON_THROW_ON_ERROR)));
    try {
        $reader->invoke($updater, $bad, hash_file('sha256', $bad));
        throw new LogicException('Missing publication package accepted.');
    } catch (RuntimeException $error) {
        pack_check($error->getMessage() === 'Missing required code or invalid release identity.', 'Missing module fails closed in offline validator.');
    }
    echo "$checks publication loading/packaging checks passed.\n";
} finally { pack_remove($temp); }
