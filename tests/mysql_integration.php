<?php
// php tests/mysql_integration.php [host] [user] [pass] [db] [port|socket]   -- needs a real MySQL 8 server
require __DIR__ . '/../src/FastVectorTable.php';
use Dj\MysqlVector\FastVectorTable;
use Dj\MysqlVector\BinaryCodec;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
[$host, $user, $pass, $dbn, $port] = [$argv[1] ?? '127.0.0.1', $argv[2] ?? 'root', $argv[3] ?? '', $argv[4] ?? 'vt', (int)($argv[5] ?? 3307)];
$db = new mysqli($host, $user, $pass, $dbn, $port);
$fail = 0;
function check(string $n, bool $ok, string $i = ''): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $n $i\n"; if (!$ok) $fail++; }

$db->query('DROP TABLE IF EXISTS itest_fast'); $db->query('DROP TABLE IF EXISTS itest_fast_meta');
$dims = 384; $N = 2000;
$raw = unpack('g*', file_get_contents(__DIR__ . '/sample_vectors.f32')); $texts = json_decode(file_get_contents(__DIR__ . '/sample_texts.json'), true);
$vecs = []; for ($i = 0; $i < $N; $i++) $vecs[] = array_slice($raw, $i * $dims, $dims);

$t = new FastVectorTable($db, 'itest', $dims, 1024, 2, '7', null, 128, 128, 1000);   // two-stage above 1000 rows
$t->initialize();
$t->fit($vecs);
$items = []; foreach ($vecs as $i => $v) $items[] = ['fk' => $i, 'text' => $texts[$i], 'vector' => $v];
$t0 = microtime(true); $ids = $t->insertMany($items); $ms = (microtime(true) - $t0) * 1000;
printf("insertMany %d rows: %.0f ms (%.2f ms/row)\n", $N, $ms, $ms / $N);
check('row count', $t->count() === $N, (string)$t->count());
check('ids returned', count($ids) === $N && $ids[0] >= 1, 'first=' . $ids[0] . ' last=' . end($ids));
$map = []; $r = $db->query('SELECT id, fk FROM itest_fast'); while ($row = $r->fetch_row()) $map[(int)$row[1]] = (int)$row[0];
$bad = 0; foreach ($ids as $i => $id) if (($map[$i] ?? -1) !== $id) $bad++;
check('every returned id matches the row inserted at that position (fk -> id)', $bad === 0, "mismatches=$bad");

// reload the model in a fresh object: must give the same codes
$t2 = new FastVectorTable($db, 'itest', $dims, 1024, 2, '7', null, 128, 128, 1000);
$t2->load();   // per-request path: model read only, no DDL
check('stored model reloaded (mean equal)', $t2->codec()->mean() === $t->codec()->mean());

foreach ([['auto (N=2000 >= 1000 => two-stage)', null, null], ['forced direct', null, false], ['forced two-stage nShort=500', 500, true]] as [$label, $ns, $force]) {
    $hit = $t2->search($vecs[17], 5, 200, $ns, $force);
    check("search [$label] returns the query row first", ($hit[0]['fk'] ?? -1) === 17 && abs($hit[0]['similarity'] - 1) < 1e-4, 'text=' . substr($hit[0]['text'] ?? '', 0, 30));
}
$ev = $t2->evaluate(30, [50, 200], 10, null);
printf("evaluate (auto mode): recall@10 = %.3f @50, %.3f @200\n", $ev[50]['recall'], $ev[200]['recall']);
check('recall@10 >= 0.9 at 200 candidates', $ev[200]['recall'] >= 0.9);

// the merged stage-1 statement (ids + vectors in one query): same answer as the old two-step path?
$q = $vecs[123]; $a = array_column($t2->search($q, 10, 200, null, false), 'id');
$exact = array_column($t2->exactSearch($q, 10), 0);
check('direct search top-10 overlaps exact top-10 by >= 8', count(array_intersect($a, $exact)) >= 8, count(array_intersect($a, $exact)) . '/10');
// EXPLAIN: do the scans use the covering indexes?
foreach (['SELECT id FROM itest_fast FORCE INDEX (idx_code) ORDER BY BIT_COUNT(code ^ UNHEX(REPEAT("ab",128))) LIMIT 200',
          'SELECT id FROM itest_fast FORCE INDEX (idx_short) ORDER BY BIT_COUNT(code_short ^ UNHEX(REPEAT("ab",16))) LIMIT 2000'] as $q) {
    $r = $db->query('EXPLAIN ' . $q)->fetch_assoc();
    printf("EXPLAIN key=%s Extra=%s\n", $r['key'], $r['Extra']);
    check('covering index scan (Using index)', str_contains((string)$r['Extra'], 'Using index'));
}

// reencodeAll(): same mean => identical codes; refit => consistent model, recall intact
$sum = fn() => $db->query('SELECT MD5(GROUP_CONCAT(HEX(code), HEX(code_short) ORDER BY id)) FROM itest_fast')->fetch_row()[0];
$before = $sum();
$db->query('UPDATE itest_fast SET code = UNHEX(REPEAT("00",128)), code_short = UNHEX(REPEAT("00",16))');
check('codes really destroyed', $sum() !== $before);
$n = $t2->reencodeAll(false, 5000, 300);
check('reencodeAll(false) touched every row', $n === $N, (string)$n);
check('reencodeAll(false) restores identical codes', $sum() === $before);
$meanBefore = $t2->codec()->mean();
$n = $t2->reencodeAll(true, 5000, 500);
check('reencodeAll(true) re-encoded every row', $n === $N, (string)$n);
$t3 = new FastVectorTable($db, 'itest', $dims, 1024, 2, '7', null, 128, 128, 1000); $t3->load();
check('refit mean stored and reloadable', $t3->codec()->mean() === $t2->codec()->mean());
$maxd = 0; foreach ($meanBefore as $i => $m) $maxd = max($maxd, abs($m - $t2->codec()->mean()[$i]));
check('refit mean close to the original (<1e-4)', $maxd < 1e-4, sprintf('max diff %.2e', $maxd));
$ev = $t3->evaluate(30, [200], 10, null);
check('recall@10 >= 0.9 after refit + reencode', $ev[200]['recall'] >= 0.9, sprintf('%.3f', $ev[200]['recall']));
exit($fail ? 1 : 0);
