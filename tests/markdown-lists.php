<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/Parsedown.php';

$md = new Parsedown();
$cases = [
    ["3. Third\n4. Fourth", "<ol start=\"3\">\n<li>Third</li>\n<li>Fourth</li>\n</ol>"],
    ["1. First\n2. Second", "<ol>\n<li>First</li>\n<li>Second</li>\n</ol>"],
    ['0. Zero', "<ol start=\"0\">\n<li>Zero</li>\n</ol>"],
    ['003. Third', "<ol start=\"3\">\n<li>Third</li>\n</ol>"],
    ['001. First', "<ol>\n<li>First</li>\n</ol>"],
    ['999999999. Last', "<ol start=\"999999999\">\n<li>Last</li>\n</ol>"],
    ['9999999999. Legacy', "<ol>\n<li>Legacy</li>\n</ol>"],
    [str_repeat('9', 1000) . '. Legacy', "<ol>\n<li>Legacy</li>\n</ol>"],
    ["3. Third\n99. Next", "<ol start=\"3\">\n<li>Third</li>\n<li>Next</li>\n</ol>"],
    ["3. Third\n\n7. Seventh", "<ol start=\"3\">\n<li>Third</li>\n</ol>\n<ol start=\"7\">\n<li>Seventh</li>\n</ol>"],
    ["- Bullet\n5. Fifth\n+ Other", "<ul>\n<li>Bullet</li>\n</ul>\n<ol start=\"5\">\n<li>Fifth</li>\n</ol>\n<ul>\n<li>Other</li>\n</ul>"],
    ["Intro\n3. **Third**\n\nEnd", "<p>Intro</p>\n<ol start=\"3\">\n<li><strong>Third</strong></li>\n</ol>\n<p>End</p>"],
    ['3" onclick="bad. Item', '<p>3&quot; onclick=&quot;bad. Item</p>'],
    ['3. <script>bad</script>', "<ol start=\"3\">\n<li>&lt;script&gt;bad&lt;/script&gt;</li>\n</ol>"],
];
foreach ($cases as $i => [$input, $expected]) {
    $actual = $md->text($input);
    if ($actual !== $expected) throw new RuntimeException('List case ' . $i . ': ' . json_encode($actual));
    echo "PASS list case $i\n";
}
echo count($cases) . " Markdown list checks passed.\n";
