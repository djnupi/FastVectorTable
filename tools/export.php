<?php
/**
 * CLI version:  php tools/export.php HOST USER PASS DATABASE TABLE [dims=384] [rows=3000] [out=vectors.f32]
 * (The browser version is web/export.php.)  Table: name_fast (float32 vec) or an old name_vectors (JSON normalized_vector).
 */
require __DIR__ . '/../src/VectorExport.php';
use Dj\MysqlVector\VectorExport;

if ($argc < 6) { fwrite(STDERR, "usage: php tools/export.php HOST USER PASS DATABASE TABLE [dims] [rows] [out]\n"); exit(1); }
[, $host, $user, $pass, $dbname, $table] = $argv;
$dims = (int)($argv[6] ?? 384); $rows = (int)($argv[7] ?? 3000); $out = $argv[8] ?? 'vectors.f32';
$db = new mysqli($host, $user, $pass, $dbname);
$fh = fopen($out, 'wb'); $n = 0;
foreach (VectorExport::sample($db, $table, $dims, $rows) as $blob) { fwrite($fh, $blob); $n++; }
fclose($fh);
echo "wrote $n vectors x $dims dims to $out\nnext: php tools/tune.php $out $dims $n 40 0.97\n";
