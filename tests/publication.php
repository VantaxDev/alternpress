<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('AP_LIBRARY', true);
require dirname(__DIR__) . '/index.php';
$checks = 0;
function publication_expect(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$at = '2030-03-31T03:00:00+02:00';
$boundary = 1901149200; // 2030-03-31 01:00:00 UTC, Europe/Rome DST boundary.
publication_expect(ap_publish_timestamp($at) === $boundary, 'Offset resolves to exact UTC instant');
publication_expect(ap_publish_timestamp('2030-03-31T01:00:00Z') === $boundary, 'UTC equivalent');
publication_expect(ap_publish_timestamp('2030-03-30T20:00:00-05:00') === $boundary, 'Negative offset equivalent');
foreach (['Europe/Rome', 'UTC', 'America/New_York'] as $zone) {
    date_default_timezone_set($zone);
    publication_expect(ap_publish_timestamp($at) === $boundary, 'Explicit offset independent of server timezone');
    $meta = ['status' => 'published', 'publish_at' => $at];
    publication_expect(ap_publication_state($meta, $boundary - 1) === 'scheduled', 'Private one second before');
    publication_expect(ap_publication_state($meta, $boundary) === 'published', 'Public at exact boundary');
    publication_expect(ap_publication_state($meta, $boundary + 1) === 'published', 'Public after boundary');
}
foreach (['2030-02-30T10:00:00Z', '2030-13-01T10:00:00Z', '2030-03-31T24:00:00Z',
    '2030-03-31T01:60:00Z', '2030-03-31T01:00:60Z', '2030-03-31T01:00:00',
    '2030-03-31', 'tomorrow', '0000-01-01T00:00:00Z', '2030-03-31T01:00:00+14:01', '2030-03-31T01:00:00+15:00',
    "2030-03-31T01:00:00Z\n", ' ', null, [], 0] as $invalid) {
    publication_expect(ap_publish_timestamp($invalid) === null, 'Invalid timestamp rejected without normalization');
    publication_expect(ap_publication_state(['publish_at' => $invalid], $boundary) === 'invalid', 'Invalid schedule fails private');
}
publication_expect(ap_publication_state([], $boundary) === 'published', 'Legacy status-free article stays public');
publication_expect(ap_publication_state(['date' => '2099-01-01'], $boundary) === 'published', 'Editorial date never implicitly schedules');
publication_expect(ap_publication_state(['publish_at' => ''], $boundary) === 'published', 'Empty schedule is immediate');
publication_expect(ap_publication_state(['status' => 'draft', 'publish_at' => '2000-01-01T00:00:00Z'], $boundary) === 'draft', 'Draft never auto-publishes');
publication_expect(ap_publish_timestamp('2028-02-29T00:00:00Z') !== null, 'Leap day accepted');
publication_expect(ap_publish_timestamp('2030-10-27T02:30:00+02:00') + 3600 === ap_publish_timestamp('2030-10-27T02:30:00+01:00'), 'Autumn repeated hour requires explicit offset');
$_SERVER['REQUEST_TIME'] = $boundary - 1;
publication_expect(!ap_published(['publish_at' => $at]), 'Shared predicate uses request clock');
$_SERVER['REQUEST_TIME'] = $boundary;
publication_expect(ap_published(['publish_at' => $at]), 'Shared predicate crosses boundary');
echo "$checks publication checks passed.\n";
