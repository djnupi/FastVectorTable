<?php
// Browser version of tools/export.php. Writes a float32 sample file (config 'out'); add ?download=1 to download it instead.
require __DIR__ . '/_bootstrap.php';
use MHz\MysqlVector\VectorExport;

$rows = max(10, min(50000, (int)$cfg['rows']));
streamStart();
try {
    $db = connect($cfg);
    $fh = fopen($cfg['out'], 'wb');
    if (!$fh) { out("Cannot write {$cfg['out']}\n"); exit; }
    $n = 0;
    foreach (VectorExport::sample($db, $cfg['table'], $cfg['dims'], $rows) as $blob) { fwrite($fh, $blob); $n++; }
    fclose($fh);
    out("wrote $n vectors x {$cfg['dims']} dims from table {$cfg['table']} to {$cfg['out']}\n");
    out("next: tune.php?source=file" . ($cfg['token'] !== '' ? "&key=..." : "") . "\n");
} catch (Throwable $e) {
    out("Error: " . $e->getMessage() . "\n");
}
