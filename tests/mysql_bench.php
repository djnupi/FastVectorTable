<?php
// php tests/mysql_bench.php SRC_DIR load N | time   -- builds / times a synthetic table on a real MySQL (host 127.0.0.1:3307 root, db vt)
require $argv[1] . '/FastVectorTable.php';
use Dj\MysqlVector\FastVectorTable;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', 'vt', 3307);
$dims = 384; mt_srand(42);
$mk = function () use ($dims) { $v = []; for ($i = 0; $i < $dims; $i++) $v[] = (mt_rand() / mt_getrandmax() - 0.5) + ($i < 64 ? 0.15 : 0.0); return $v; };
$t = new FastVectorTable($db, 'bench', $dims, 1024, 2, '7', null, 128, 128, 200000);
$mode = $argv[2] ?? 'time';
if ($mode === 'load') {
    $N = (int)$argv[3];
    $db->query('DROP TABLE IF EXISTS bench_fast'); $db->query('DROP TABLE IF EXISTS bench_fast_meta');
    $t->initialize(); $sample = []; for ($i = 0; $i < 3000; $i++) $sample[] = $mk(); $t->fit($sample);
    $t0 = microtime(true); $done = 0;
    while ($done < $N) { $batch = []; for ($i = 0; $i < 2000 && $done < $N; $i++, $done++) $batch[] = ['fk' => $done, 'text' => "row $done", 'vector' => $mk()]; $t->insertMany($batch); }
    printf("loaded %d rows in %.1f s\n", $N, microtime(true) - $t0);
    $db->query('ANALYZE TABLE bench_fast');
    exit;
}
$t->initialize();
$qs = []; for ($i = 0; $i < 25; $i++) $qs[] = $mk();
$run = function (string $label, callable $f) use ($qs) { $f($qs[0]); $ts = []; foreach ($qs as $q) { $a = microtime(true); $f($q); $ts[] = (microtime(true) - $a) * 1000; } sort($ts); printf("%-44s median %.1f ms   p90 %.1f ms\n", $label, $ts[intdiv(count($ts), 2)], $ts[(int)(count($ts) * 0.9)]); };
echo "rows (count): " . $t->count() . "\n";
$run('search direct   (1024-bit scan, nPre=200)', fn($q) => $t->search($q, 10, 200, null, false));
$run('search 2-stage  (128-bit scan, nShort=2000)', fn($q) => $t->search($q, 10, 200, 2000, true));
$run('search direct   nPre=50', fn($q) => $t->search($q, 10, 50, null, false));
