<?php
declare(strict_types=1);

/** AlternPress's small, bundled Markdown renderer. No third-party dependencies.
 * The historical class name is retained for compatibility with the original blog.
 * Raw HTML is always escaped; only validated URLs enter generated attributes.
 */
final class Parsedown {
    public const MAX_BYTES = 2097152;
    // Share the supported block grammar with table continuation lookahead.
    private const HEADING = '/^(#{1,6})\s+(.*)$/';
    private const LIST_ITEM = '/^\s*(?:([-*+])|([0-9]+)\.)\s+(.*)$/';
    private const QUOTE = '/^\s*>\s?(.*)$/';
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
    private function codeSpans(string $text, callable $tokenize): string {
        // Record the last maximal run of each length. An unmatched opener can
        // then stay literal without repeatedly scanning the rest of the input.
        // A matched opener consumes through its first equal-length closer;
        // the two passes are linear, with at most sqrt(2 * bytes) map entries.
        $last = []; $offset = 0;
        while (($start = strpos($text, '`', $offset)) !== false) {
            $length = strspn($text, '`', $start);
            $last[$length] = $start;
            $offset = $start + $length;
        }
        $result = ''; $offset = 0;
        while (($start = strpos($text, '`', $offset)) !== false) {
            $result .= substr($text, $offset, $start - $offset);
            $length = strspn($text, '`', $start);
            $offset = $start + $length;
            if ($last[$length] === $start) {
                $result .= str_repeat('`', $length);
                continue;
            }
            $search = $offset;
            do {
                $close = strpos($text, '`', $search);
                $closeLength = strspn($text, '`', $close);
                $search = $close + $closeLength;
            } while ($closeLength !== $length);
            $code = substr($text, $offset, $close - $offset);
            // Exactly one ASCII space at each end is padding, unless the
            // entire content is spaces. Internal whitespace stays literal.
            if (str_starts_with($code, ' ') && str_ends_with($code, ' ')
                && strspn($code, ' ') !== strlen($code)) $code = substr($code, 1, -1);
            $result .= $tokenize('<code>' . $this->esc($code) . '</code>', $code);
            $offset = $search;
        }
        return $result . substr($text, $offset);
    }
    private function inline(string $text, int $depth = 0): string {
        if ($depth > 4) return $this->esc($text);
        // Strip caller-supplied sentinels once; recursive link labels must
        // preserve the already-created, immutable outer code placeholders.
        if ($depth === 0) $text = str_replace("\x1A", '', $text);
        $tokens = []; $codeText = []; $images = [];
        $tokenize = static function(string $html, ?string $plainCode = null) use (&$tokens, &$codeText): string {
            $key = "\x1A" . count($tokens) . "\x1A";
            $tokens[$key] = $html;
            if ($plainCode !== null) $codeText[$key] = $plainCode;
            return $key;
        };
        $text = $this->codeSpans($text, $tokenize);
        // Delimiters cannot restart inside a candidate. This keeps malformed
        // bracket runs linear instead of rescanning the remainder at each '['.
        $text = preg_replace_callback('/(!?)\[([^\[\]\n]*)\]\(([^\s()\[\]]*)(?:[ \t]+"[^"\n]*")?\)/', function($m) use ($tokenize, $depth, &$tokens, &$codeText, &$images) {
            $url = $this->esc($this->safeUrl($m[3]));
            // Alt is an attribute, not inline HTML. Restore only code's plain
            // text before escaping once; never insert <code> markup here.
            if ($m[1] === '!') {
                $alt = preg_replace_callback('/\x1A[0-9]+\x1A/', static fn($match) => $codeText[$match[0]] ?? '', $m[2]);
                $key = $tokenize('<img src="' . $url . '" alt="' . $this->esc($alt) . '" loading="lazy">');
                $images[$key] = $tokens[$key];
                return $key;
            }
            $label = preg_replace_callback('/\x1A[0-9]+\x1A/', static fn($match) => $tokens[$match[0]] ?? '', $this->inline($m[2], $depth + 1));
            return $tokenize('<a href="' . $url . '" rel="noopener noreferrer">' . $label . '</a>');
        }, $text);
        // One bounded follow-up pass supports only [<one image token>](url).
        // Never reparse token HTML or wrap code/anchor tokens in another link.
        // Other label nesting stays literal; do not repeat this pass.
        $text = preg_replace_callback('/(?<!!)\[(\x1A[0-9]+\x1A)\]\(([^\s()\[\]]*)(?:[ \t]+"[^"\n]*")?\)/', function($m) use ($tokenize, &$images) {
            if (!isset($images[$m[1]])) return $m[0];
            $url = $this->esc($this->safeUrl($m[2]));
            return $tokenize('<a href="' . $url . '" rel="noopener noreferrer">' . $images[$m[1]] . '</a>');
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
    private function openingFence(string $line): ?array {
        // Share this boundary rule with table lookahead. Backtick info cannot
        // contain backticks; ignored info must never become HTML attributes.
        if (!preg_match('/^( {0,3})(`{3,}|~{3,})(.*)$/D', $line, $match)
            || ($match[2][0] === '`' && str_contains($match[3], '`'))) return null;
        return $match;
    }
    private function interruptsTable(string $line): bool {
        if ($this->openingFence($line) !== null) return true;
        $line = rtrim($line);
        return preg_match(self::HEADING, $line) === 1
            || preg_match(self::LIST_ITEM, $line) === 1
            || preg_match(self::QUOTE, $line) === 1;
    }
    public function text(string $markdown): string {
        if (strlen($markdown) > self::MAX_BYTES) throw new RuntimeException('Markdown exceeds the 2 MB limit.');
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        if (count($lines) > 40000) throw new RuntimeException('Markdown contains too many lines.');
        $html = []; $paragraph = []; $list = ''; $code = null;
        $fenceMarker = ''; $fenceLength = 0; $fenceIndent = 0;
        $flush = function() use (&$html, &$paragraph, &$list) {
            if ($paragraph) { $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>'; $paragraph = []; }
            if ($list !== '') { $html[] = '</' . $list . '>'; $list = ''; }
        };
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $rawLine = $lines[$i];
            // Inside code, only a same-character fence at least as long as the
            // opener can close the block. Everything else remains literal.
            if ($code !== null) {
                if (preg_match('/^ {0,3}(`+|~+)[ \t]*$/D', $rawLine, $m)
                    && $m[1][0] === $fenceMarker && strlen($m[1]) >= $fenceLength) {
                    $html[] = '<pre><code>' . $this->esc(implode("\n", $code)) . '</code></pre>';
                    $code = null;
                } else {
                    $code[] = substr($rawLine, min($fenceIndent, strspn($rawLine, ' ')));
                }
                continue;
            }
            if (($m = $this->openingFence($rawLine)) !== null) {
                $flush(); $code = [];
                $fenceMarker = $m[2][0]; $fenceLength = strlen($m[2]); $fenceIndent = strlen($m[1]);
                continue;
            }
            $line = rtrim($rawLine);
            if (trim($line) === '') { $flush(); continue; }
            if (preg_match(self::HEADING, $line, $m)) {
                $flush(); $level = strlen($m[1]); $html[] = '<h' . $level . '>' . $this->inline($m[2]) . '</h' . $level . '>'; continue;
            }
            // Thematic breaks take precedence over list markers. Require at
            // least three identical markers; spaces/tabs may separate them.
            if (preg_match('/^ {0,3}(?:(?:-[ \t]*){3,}|(?:\*[ \t]*){3,}|(?:_[ \t]*){3,})$/D', $line)) {
                $flush(); $html[] = '<hr>'; continue;
            }
            if (preg_match(self::LIST_ITEM, $line, $m)) {
                $type = $m[1] !== '' ? 'ul' : 'ol';
                if ($list !== $type) {
                    $flush();
                    // Only the first marker sets the start. Bound conversion
                    // to nine digits; retain legacy rendering for longer runs.
                    $start = $type === 'ol' && strlen($m[2]) <= 9 ? (int)$m[2] : 1;
                    $html[] = '<' . $type . ($start !== 1 ? ' start="' . $start . '"' : '') . '>';
                    $list = $type;
                }
                $html[] = '<li>' . $this->inline($m[3]) . '</li>'; continue;
            }
            if (preg_match(self::QUOTE, $line, $m)) {
                $flush(); $html[] = '<blockquote>' . $this->inline($m[1]) . '</blockquote>'; continue;
            }
            if (str_contains($line, '|') && isset($lines[$i + 1])) {
                $headers = $this->cells($line); $delimiters = $this->cells($lines[$i + 1]);
                $valid = count($headers) === count($delimiters) && count($headers) > 1;
                foreach ($delimiters as $cell) if (!preg_match('/^:?-+:?$/', $cell)) $valid = false;
                if ($valid) {
                    $flush(); $html[] = '<div class="overflow-auto"><table><thead><tr>';
                    foreach ($headers as $cell) $html[] = '<th>' . $this->inline($cell) . '</th>';
                    $html[] = '</tr></thead><tbody>'; $i++;
                    while (isset($lines[$i + 1]) && str_contains($lines[$i + 1], '|') && trim($lines[$i + 1]) !== ''
                        && !$this->interruptsTable($lines[$i + 1])) {
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
