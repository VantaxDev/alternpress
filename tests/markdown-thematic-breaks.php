<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/Parsedown.php';
$md = new Parsedown();
$count = 0;
function expect(string $input, string $expected, string $label): void {
    global $md, $count;
    $actual = $md->text($input);
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
    $count++;
    echo "PASS $label\n";
}
foreach (['---', '***', '___', '- - -', '* * *', '_ _ _', '----', '****', '____', '  *  *  *', "_\t_\t_", '   ---', '* * *   '] as $input) {
    expect($input, '<hr>', 'thematic break ' . json_encode($input));
}
foreach (['--', '__', '-_*', '--- text', 'text ___', '    ___', '+ + +'] as $input) {
    if (str_contains($md->text($input), '<hr>')) throw new RuntimeException('false thematic break: ' . $input);
    $count++;
    echo 'PASS non-break ' . json_encode($input) . "\n";
}
expect("before\n* * *\nafter", "<p>before</p>\n<hr>\n<p>after</p>", 'break interrupts paragraph');
expect("- item\n- - -\n- next", "<ul>\n<li>item</li>\n</ul>\n<hr>\n<ul>\n<li>next</li>\n</ul>", 'break closes list before new list');
expect("* item\n* next", "<ul>\n<li>item</li>\n<li>next</li>\n</ul>", 'ordinary list preserved');
expect("```\n* * *\n___\n```", "<pre><code>* * *\n___</code></pre>", 'code keeps separators literal');
expect('--- <script>alert(1)</script>', '<p>--- &lt;script&gt;alert(1)&lt;/script&gt;</p>', 'non-break HTML escaped');
echo "$count checks passed.\n";
