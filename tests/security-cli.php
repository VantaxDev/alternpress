<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('AP_CLI_FUNCTIONS_ONLY', true);
require dirname(__DIR__) . '/alt-cli';
$count = 0;
function cli_check(bool $value, string $message): void { global $count; if (!$value) throw new RuntimeException($message); $count++; }
foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::1'] as $address) cli_check(!cli_public_ipv4($address), 'Non-global address rejected: ' . $address);
cli_check(cli_public_ipv4('1.1.1.1'), 'Public IPv4 accepted');
foreach (['file:///etc/passwd', 'https://example.com:22', 'http://user:pass@example.com', 'http://example.com/ bad', "http://example.com/\r\nHeader:bad", 'http://example.com\\@127.0.0.1'] as $url) cli_check(cli_external_parts($url) === null, 'Unsafe external URL rejected');
cli_check(cli_external_parts('https://example.com/path')['port'] === 443, 'Normal HTTPS URL accepted');
cli_check(!cli_local_file('../../etc/passwd'), 'Local references stay inside site');
echo "$count CLI security checks passed.\n";
