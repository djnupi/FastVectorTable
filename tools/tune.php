<?php
/**
 * CLI version:  php tools/tune.php vectors.f32 384 [rows=3000] [queries=40] [targetRecall=0.97]
 * (The browser version is web/tune.php.)
 */
require __DIR__ . '/../src/Tuner.php';
require __DIR__ . '/../src/VectorExport.php';
use MHz\MysqlVector\Tuner;
use MHz\MysqlVector\VectorExport;

$file = $argv[1] ?? null; $dims = (int)($argv[2] ?? 0);
if (!$file || !$dims) { fwrite(STDERR, "usage: php tools/tune.php vectors.f32 DIMS [rows] [queries] [targetRecall]\n"); exit(1); }
Tuner::run(VectorExport::readFile($file, $dims, (int)($argv[3] ?? 3000)), $dims, (int)($argv[4] ?? 40), (float)($argv[5] ?? 0.97), fn($s) => print($s));
