<?php
// php tests/export_test.php -- tests the crop/normalize/pack step of tools/export.php (the SQL part needs a server)
require __DIR__ . '/../src/VectorExport.php';
use MHz\MysqlVector\VectorExport;
mt_srand(3); $ok = true;
$v768 = []; for ($i = 0; $i < 768; $i++) $v768[] = mt_rand() / mt_getrandmax() - 0.5;
$blob = VectorExport::toF32($v768, 384);
$back = array_values(unpack('g*', $blob));
$norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $back)));
$s = sqrt(array_sum(array_map(fn($x) => $x * $x, array_slice($v768, 0, 384))));
$err = max(array_map(fn($a, $b) => abs($a - $b / $s), $back, array_slice($v768, 0, 384)));
$ok = $ok && strlen($blob) === 384 * 4 && abs($norm - 1) < 1e-6 && $err < 1e-6;
printf("%s  crop 768->384, normalize, float32 pack: bytes=%d norm=%.8f max err=%.2e\n", $ok ? 'PASS' : 'FAIL', strlen($blob), $norm, $err);
$j = VectorExport::toF32(json_decode(json_encode($v768), true), 384);   // JSON round trip as in the old table
$ok = $ok && $j === $blob; echo ($j === $blob ? 'PASS' : 'FAIL') . "  JSON column path gives identical bytes\n";
try { VectorExport::toF32([1.0, 2.0], 5); echo "FAIL  short vector not rejected\n"; $ok = false; } catch (RuntimeException $e) { echo "PASS  short vector rejected\n"; }
// the file it writes must be readable by tune.php's reader (same format)
file_put_contents('/tmp/claude-0/-home-claude/6bbb9356-05a8-50a4-993b-557a5084a940/scratchpad/exp.f32', $blob . $blob);
$r = unpack('g*', file_get_contents('/tmp/claude-0/-home-claude/6bbb9356-05a8-50a4-993b-557a5084a940/scratchpad/exp.f32'));
$ok = $ok && count($r) === 768; echo (count($r) === 768 ? 'PASS' : 'FAIL') . "  file reads back as 2 x 384 floats\n";
exit($ok ? 0 : 1);
