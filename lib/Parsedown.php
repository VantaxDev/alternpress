<?php
declare(strict_types=1);

/** AlternPress's small, bundled Markdown renderer. No third-party dependencies.
 * The historical class name is retained for compatibility with the original blog.
 * Raw HTML is always escaped; only validated URLs enter generated attributes.
 */
final class Parsedown {
    public const MAX_BYTES = 2097152;
    public function setSafeMode(bool $enabled): void { /* HTML is always escaped. */ }
    private function esc(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function safeUrl(string $url): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url, '//')) return '#';
        if (preg_match('#\Ahttps?://[^/]+#i', $url)) return filter_var($url, FILTER_VALIDATE_URL) ? $url : '#';
        if (str_starts_with(strtolower($url), 'mailto:')) {
            $address = rawurldecode(explode('?', substr($url, 7), 2)[0]);
            return filter_var($address, FILTER_VALIDATE_EMAIL) ? $url : '#';
        }
        if (str_contains($url, ':')) return '#';
        return $url;
    }
    private function inline(string $text, int $depth = 0): string {
        if ($depth > 4) return $this->esc($text);
        $text = str_replace("\x1A", '', $text);
        $tokens = [];
        $tokenize = static function(string $html) use (&$tokens): string {
            $key = "\x1A" . count($tokens) . "\x1A"; $tokens[$key] = $html; return $key;
        };
        $text = preg_replace_callback('/`([^`\n]+)`/', fn($m) => $tokenize('<code>' . $this->esc($m[1]) . '</code>'), $text);
        // Delimiters cannot restart inside a candidate. This keeps malformed
        // bracket runs linear instead of rescanning the remainder at each '['.
        $text = preg_replace_callback('/(!?)\[([^\[\]\n]*)\]\(([^\s()\[\]]*)(?:[ \t]+"[^"\n]*")?\)/', function($m) use ($tokenize, $depth) {
            $url = $this->esc($this->safeUrl($m[3]));
            if ($m[1] === '!') return $tokenize('<img src="' . $url . '" alt="' . $this->esc($m[2]) . '" loading="lazy">');
            return $tokenize('<a href="' . $url . '" rel="noopener noreferrer">' . $this->inline($m[2], $depth + 1) . '</a>');
        }, $text);
        $text = $this->esc($text);
        $text = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $text);
        return strtr($text, $tokens);
    }
    private function cells(string $line): array {
        $line = trim($line);
        $line = preg_replace('/^\||(?<!\\\\)\|$/', '', $line);
        return array_map(static fn($cell) => trim(str_replace('\\|', '|', $cell)), preg_split('/(?<!\\\\)\|/', $line));
    }
    public function text(string $markdown): string {
        if (strlen($markdown) > self::MAX_BYTES) throw new RuntimeException('Markdown exceeds the 2 MB limit.');
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        if (count($lines) > 40000) throw new RuntimeException('Markdown contains too many lines.');
        $html = []; $paragraph = []; $list = ''; $code = null;
        $flush = function() use (&$html, &$paragraph, &$list) {
            if ($paragraph) { $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>'; $paragraph = []; }
            if ($list !== '') { $html[] = '</' . $list . '>'; $list = ''; }
        };
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if (preg_match('/^\s*```/', $line)) {
                $flush();
                if ($code === null) $code = [];
                else { $html[] = '<pre><code>' . $this->esc(implode("\n", $code)) . '</code></pre>'; $code = null; }
                continue;
            }
            if ($code !== null) { $code[] = $line; continue; }
            if (trim($line) === '') { $flush(); continue; }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $flush(); $level = strlen($m[1]); $html[] = '<h' . $level . '>' . $this->inline($m[2]) . '</h' . $level . '>'; continue;
            }
            if (preg_match('/^\s*(?:([-*+])|([0-9]+)\.)\s+(.*)$/', $line, $m)) {
                $type = $m[1] !== '' ? 'ul' : 'ol';
                if ($list !== $type) { $flush(); $html[] = '<' . $type . '>'; $list = $type; }
                $html[] = '<li>' . $this->inline($m[3]) . '</li>'; continue;
            }
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $flush(); $html[] = '<blockquote>' . $this->inline($m[1]) . '</blockquote>'; continue;
            }
            if (preg_match('/^\s*(?:---+|\*\*\*+)\s*$/', $line)) { $flush(); $html[] = '<hr>'; continue; }
            if (str_contains($line, '|') && isset($lines[$i + 1])) {
                $headers = $this->cells($line); $delimiters = $this->cells($lines[$i + 1]);
                $valid = count($headers) === count($delimiters) && count($headers) > 1;
                foreach ($delimiters as $cell) if (!preg_match('/^:?-+:?$/', $cell)) $valid = false;
                if ($valid) {
                    $flush(); $html[] = '<div class="overflow-auto"><table><thead><tr>';
                    foreach ($headers as $cell) $html[] = '<th>' . $this->inline($cell) . '</th>';
                    $html[] = '</tr></thead><tbody>'; $i++;
                    while (isset($lines[$i + 1]) && str_contains($lines[$i + 1], '|') && trim($lines[$i + 1]) !== '') {
                        $row = $this->cells($lines[++$i]); $html[] = '<tr>';
                        foreach ($headers as $column => $_) $html[] = '<td>' . $this->inline($row[$column] ?? '') . '</td>';
                        $html[] = '</tr>';
                    }
                    $html[] = '</tbody></table></div>'; continue;
                }
            }
            if ($list !== '') $flush();
            $paragraph[] = trim($line);
        }
        $flush();
        if ($code !== null) $html[] = '<pre><code>' . $this->esc(implode("\n", $code)) . '</code></pre>';
        return implode("\n", $html);
    }
}
