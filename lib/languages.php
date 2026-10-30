<?php
declare(strict_types=1);

/** Small, path-safe language-tag subset; no locale database or translation service. */
function ap_language_code_valid(mixed $code): bool {
    return is_string($code) && strlen($code) <= 35
        && preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/D', $code) === 1;
}
function ap_language_list(mixed $languages): array {
    if (!is_array($languages) || !array_is_list($languages) || count($languages) < 1 || count($languages) > 16) throw new RuntimeException('Configure 1..16 languages as a list.');
    $seen = [];
    foreach ($languages as $language) {
        if (!ap_language_code_valid($language) || isset($seen[strtolower($language)])) throw new RuntimeException('Invalid or duplicate language code.');
        $seen[strtolower($language)] = true;
    }
    return $languages;
}
function ap_language_name(string $language): string {
    return match ($language) { 'it' => 'Italiano', 'en' => 'English', default => strtoupper($language) };
}
