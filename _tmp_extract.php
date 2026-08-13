<?php
$f = 'C:\Users\DELL\.claude\projects\C--xampp-htdocs-Mostmerit-merit-drives---Copy-v1\0e12a922-f98d-42a7-b54f-bd53d0ece907.jsonl';
$fh = fopen($f, 'r');
$n = 0;
while (($line = fgets($fh)) !== false) {
    $n++;
    $j = json_decode($line, true);
    if (!is_array($j)) continue;
    $content = $j['message']['content'] ?? null;
    if (!is_array($content)) continue;
    foreach ($content as $blk) {
        if (($blk['type'] ?? '') !== 'tool_use') continue;
        $name = $blk['name'] ?? '';
        if ($name !== 'Edit') continue;
        $inp = $blk['input'] ?? [];
        $fp = $inp['file_path'] ?? '';
        if (stripos($fp, 'dashboard.php') === false) continue;
        echo "=== line $n  file=$fp ===\n";
        echo "--- OLD ---\n" . ($inp['old_string'] ?? '') . "\n";
        echo "--- NEW ---\n" . ($inp['new_string'] ?? '') . "\n\n";
    }
}
fclose($fh);
