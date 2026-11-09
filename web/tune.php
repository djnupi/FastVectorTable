<?php
// Browser version of tools/tune.php. Reads a sample from the table (default) or from the exported file and prints the grid.
require __DIR__ . '/_bootstrap.php';
use MHz\MysqlVector\Tuner;
use MHz\MysqlVector\VectorExport;

$rows = max(100, min(5000, (int)$cfg['rows']));
streamStart();
try {
    if ($cfg['source'] === 'file') {
        if (!is_file($cfg['out'])) { out("No file at {$cfg['out']} - run export.php first, or use source=db.\n"); exit; }
        $vecs = VectorExport::readFile($cfg['out'], $cfg['dims'], $rows);
        out("source: file {$cfg['out']}\n");
    } else {
        $db = connect($cfg);
        $vecs = [];
        foreach (VectorExport::sample($db, $cfg['table'], $cfg['dims'], $rows) as $blob) { $vecs[] = array_values(unpack('g*', $blob)); }
        out("source: table {$cfg['table']}\n");
    }
    if (count($vecs) < 100) { out("Only " . count($vecs) . " vectors; need at least 100 for a meaningful test.\n"); exit; }
    out("This takes a while (PHP does the work); lines appear as each setting finishes.\n\n");
    Tuner::run($vecs, $cfg['dims'], $cfg['queries'], $cfg['target'], 'out');
} catch (Throwable $e) {
    out("Error: " . $e->getMessage() . "\n");
}
