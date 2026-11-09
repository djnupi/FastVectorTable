<?php
// php tests/selftest.php   -- needs no MySQL server. Tests the PHP logic; the SQL strings are NOT exercised here.
require __DIR__ . '/../src/InMemoryVectorTable.php';
use MHz\MysqlVector\BinaryCodec;
use MHz\MysqlVector\FastVectorTable;
use MHz\MysqlVector\InMemoryVectorTable;

$fail = 0;
function check(string $name, bool $ok, string $info = ''): void { global $fail; echo ($ok ? "PASS" : "FAIL") . "  $name $info\n"; if (!$ok) $fail++; }

// 1. the PHP encoder must produce exactly the bits of the numpy reference implementation
foreach (json_decode(file_get_contents(__DIR__ . '/codec_fixture.json'), true) as $k => $c) {
    $codec = new BinaryCodec($c['dims'], $c['bits'], $c['rounds'], $c['seed'], $c['mean']);
    $same = 0;
    foreach ($c['vectors'] as $i => $v) { $same += bin2hex($codec->encode($v)) === $c['codes'][$i] ? 1 : 0; }
    check("codec parity case $k (dims={$c['dims']} bits={$c['bits']} rounds={$c['rounds']})", $same === count($c['vectors']), "$same/" . count($c['vectors']));
}

// 2. end-to-end on 2000 REAL BGE-small embeddings, with an in-memory stand-in for the database
$dims = 384; $N = 2000;
$raw = unpack('g*', file_get_contents(__DIR__ . '/sample_vectors.f32'));
$texts = json_decode(file_get_contents(__DIR__ . '/sample_texts.json'), true);
$vecs = []; for ($i = 0; $i < $N; $i++) $vecs[] = array_slice($raw, $i * $dims, $dims);
$t = new InMemoryVectorTable($dims, 1024);
$t->fit($vecs);
$items = []; foreach ($vecs as $i => $v) $items[] = ['fk' => $i, 'text' => $texts[$i], 'vector' => $v];
$t0 = microtime(true); $t->insertMany($items); $ins = (microtime(true) - $t0) * 1000 / $N;
printf("encode+pack: %.2f ms per vector (dims=384, 1024 bits)\n", $ins);

$hit = $t->search($vecs[17], 3, 50);
check('search returns the query row first with similarity ~1', $hit[0]['fk'] === 17 && abs($hit[0]['similarity'] - 1) < 1e-5, "text=" . substr($hit[0]['text'], 0, 40));
$ev = $t->evaluate(40, [10, 50, 200, 500]);
foreach ($ev as $np => $r) printf("  nPre=%-4d recall@10=%.3f  score=%.3f\n", $np, $r['recall'], $r['score']);
check('recall@10 >= 0.90 with 200 candidates (N=2000)', $ev[200]['recall'] >= 0.90);
check('recall grows with candidates', $ev[10]['recall'] <= $ev[50]['recall'] && $ev[50]['recall'] <= $ev[200]['recall']);

// baseline in the same harness: the article's code, i.e. sign of the first 256 dims, no centering/rotation
$sign = fn($v) => (function() use ($v) { $b = array_fill(0, 32, 0); for ($i = 0; $i < 256; $i++) if ($v[$i] > 0) $b[$i >> 3] |= 1 << ($i & 7); return pack('C*', ...$b); })();
$codes = array_map($sign, $vecs); $ids = range(1, $N); mt_srand(5); shuffle($ids); $rec = [50 => 0, 200 => 0];
$qs = array_slice($ids, 0, 40);
foreach ($qs as $qid) {
    $truth = array_column(array_values(array_filter($t->exactSearch($vecs[$qid - 1], 11), fn($r) => $r[0] !== $qid)), 0);
    $d = []; foreach ($codes as $i => $c) if ($i + 1 !== $qid) $d[$i + 1] = BinaryCodec::hamming($codes[$qid - 1], $c);
    asort($d);
    foreach ([50, 200] as $np) {
        $cand = array_slice(array_keys($d), 0, $np); $s = [];
        foreach ($cand as $id) { $s[$id] = array_sum(array_map(fn($a, $b) => $a * $b, $vecs[$qid - 1], $vecs[$id - 1])); }
        arsort($s); $rec[$np] += count(array_intersect(array_slice(array_keys($s), 0, 10), $truth)) / 10;
    }
}
printf("baseline (article method 1, 256 raw bits): recall@10 = %.3f at 50, %.3f at 200 candidates\n", $rec[50] / 40, $rec[200] / 40);

// 3. Matryoshka options: crop + two-tier. (BGE-small is NOT Matryoshka-trained, so quality numbers here only show the mechanics.)
$c = BinaryCodec::cropNormalize([3.0, 4.0, 12.0], 2);
check('cropNormalize crops and re-normalizes', abs($c[0] - 0.6) < 1e-12 && abs($c[1] - 0.8) < 1e-12 && count($c) === 2);
$ceil = $t->prefixCeiling(20, [64, 128, 256], 10, 100);
echo "  true top-10 found within 100 candidates ranked by exact cosine on first k dims: " . json_encode($ceil) . "\n";
check('prefix ceiling is increasing in k', $ceil[64] <= $ceil[128] && $ceil[128] <= $ceil[256] && $ceil[256] <= 1.0);

$one = new InMemoryVectorTable($dims, 512, 256, null);                   // single tier, long code from first 256 dims only
$one->fit($vecs); $one->insertMany($items);
$two = new InMemoryVectorTable($dims, 512, 256, 128, 256);          // + short tier: 128 dims -> 256 bits
$two->fit($vecs); $two->insertMany($items);
$same = 0; foreach ([3, 40, 77, 500, 1234] as $qi) {
    $a = array_column($one->search($vecs[$qi], 10, 100), 'id'); $b = array_column($two->search($vecs[$qi], 10, 100, $N), 'id');
    $same += $a === $b ? 1 : 0;
}
check('two-tier with nShort = N gives exactly the single-tier result', $same === 5, "$same/5");
// 4. automatic switch: both codes are always stored; the mode follows the (estimated) row count
$low = new InMemoryVectorTable($dims, 512, 256, 128, 256, 1000);   // N=2000 >= 1000  -> two-stage
$high = new InMemoryVectorTable($dims, 512, 256, 128, 256, 5000);  // N=2000 <  5000  -> direct long-code scan
foreach ([$low, $high] as $tb) { $tb->fit($vecs); $tb->insertMany($items); }
$low->search($vecs[3], 10, 100);  $m1 = $low->lastUsedShort;
$high->search($vecs[3], 10, 100); $m2 = $high->lastUsedShort;
$high->search($vecs[3], 10, 100, null, true); $m3 = $high->lastUsedShort;
$low->search($vecs[3], 10, 100, null, false); $m4 = $low->lastUsedShort;
$high->search($vecs[3], 10, 100, 500); $m5 = $high->lastUsedShort;
check('auto mode: big table uses two-stage, small table does not', $m1 === true && $m2 === false, json_encode([$m1, $m2]));
check('explicit $twoStage and explicit $nShort override the automatic choice', $m3 === true && $m4 === false && $m5 === true);
$dflt = new InMemoryVectorTable($dims, 512, 256); $dflt->fit($vecs); $dflt->insertMany(array_slice($items, 0, 5));
check('short key exists by default and is 128 bits (16 bytes)', strlen($dflt->rows[0]['short'] ?? '') === 16);
$off = new InMemoryVectorTable($dims, 512, 256, null); $off->fit($vecs); $off->insertMany(array_slice($items, 0, 5));
check('null switches the short key off', $off->rows[0]['short'] === null);
$tiny = new InMemoryVectorTable(64, 256); $tiny->insertMany([['fk' => 1, 'text' => 'x', 'vector' => array_slice($vecs[0], 0, 64)]]);
check('no short key when dims are not larger than the short crop', $tiny->rows[0]['short'] === null);
check('short code is stored even when the small-table mode is used', strlen($high->rows[0]['short']) === 32 && strlen($high->rows[0]['code']) === 64);
foreach ([['single tier, crop 256, 512 bits', $one, null], ['two tier, nShort=500', $two, 500], ['two tier, nShort=200', $two, 200]] as [$label, $tbl, $ns]) {
    $e = $tbl->evaluate(30, [100], 10, $ns);
    printf("  %-34s recall@10 (100 candidates) = %.3f\n", $label, $e[100]['recall']);
}
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
