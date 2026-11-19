<?php
declare(strict_types=1);

/** Freeze publication decisions at request start; no client-controlled clock. */
function ap_publication_now(): int { return (int) ($_SERVER['REQUEST_TIME'] ?? time()); }

/** Strict RFC3339 seconds + explicit offset. Never normalize invalid dates. */
function ap_publish_timestamp(mixed $value): ?int {
    if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/D', $value)) return null;
    if (str_starts_with($value, '0000-') || preg_match('/[+-]14:(?!00)/', $value)) return null;
    $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
    return $date && $date->format('Y-m-d\TH:i:sP') === $normalized ? $date->getTimestamp() : null;
}

function ap_publication_state(array $meta, ?int $now = null): string {
    if (($meta['status'] ?? 'published') !== 'published') return 'draft';
    if (!array_key_exists('publish_at', $meta) || $meta['publish_at'] === '') return 'published';
    $timestamp = ap_publish_timestamp($meta['publish_at']);
    if ($timestamp === null) return 'invalid';
    return $timestamp <= ($now ?? ap_publication_now()) ? 'published' : 'scheduled';
}

function ap_published(array $meta): bool { return ap_publication_state($meta) === 'published'; }
