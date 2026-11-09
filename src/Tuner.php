<?php

namespace Dj\MysqlVector;

require_once __DIR__ . '/InMemoryVectorTable.php';

/**
 * Grid-search the settings on a sample of your vectors, without a database.
 * Two-tier rows: tier 1 keeps 10% of the sample (a sample is too small for an absolute nShort like 2000,
 * which would keep everything and test nothing). "scan B/row" for two-tier = short code + 10% of the long code.
 */
final class Tuner
{
    /**
     * @param float[][] $vecs   vectors at the resolution you will store
     * @param callable  $print  receives each output line (string)
     */
    public static function run(array $vecs, int $dims, int $queries, float $target, callable $print): void
    {
        $N = count($vecs);
        $items = [];
        foreach ($vecs as $i => $v) {
            $items[] = ['fk' => $i, 'text' => '', 'vector' => $v];
        }
        $print("rows=$N dims=$dims queries=$queries\n");

        $configs = [];
        foreach ([128, 192, 256, $dims] as $crop) {
            foreach ([256, 512, 1024] as $bits) {
                if ($crop > $dims || $bits < $crop) {
                    continue;
                }
                $configs[] = ["single crop=$crop bits=$bits", $crop, $bits, null, 256, null];
            }
        }
        foreach ([[128, 256, $dims, 1024], [128, 256, 256, 512], [128, 128, 256, 512]] as [$sc, $sb, $lc, $lb]) {
            if ($lc <= $dims) {
                $configs[] = ["two-tier short $sc/$sb + long $lc/$lb", $lc, $lb, $sc, $sb, max(200, intdiv($N, 10))];
            }
        }

        $probe = new InMemoryVectorTable($dims, 256);
        $probe->fit($vecs);
        $probe->insertMany($items);
        $ks = array_values(array_filter([64, 128, 192, 256, 384, 512], fn($k) => $k < $dims));
        $print("\nCeiling: true top-10 found within 200 candidates ranked by EXACT cosine on the first k dims\n");
        foreach ($probe->prefixCeiling($queries, $ks, 10, 200) as $k => $r) {
            $print(sprintf("  k=%-4d %.3f\n", $k, $r));
        }

        $print(sprintf("\n%-42s %10s %9s %9s\n", 'config', 'scan B/row', 'rec@50', 'rec@200'));
        $best = null;
        foreach ($configs as [$label, $crop, $bits, $sc, $sb, $nShort]) {
            $t = new InMemoryVectorTable($dims, $bits, $crop, $sc, $sb);
            $t->fit($vecs);
            $t->insertMany($items);
            $e = $t->evaluate($queries, [50, 200], 10, $nShort);
            $scan = $sc ? round($sb / 8 + 0.1 * $bits / 8, 1) : intdiv($bits, 8);
            $print(sprintf("%-42s %10.1f %9.3f %9.3f\n", $label, $scan, $e[50]['recall'], $e[200]['recall']));
            if ($e[200]['recall'] >= $target && ($best === null || $scan < $best[1])) {
                $best = [$label, $scan, $e[200]['recall']];
            }
        }
        $print($best
            ? "\nCheapest scan with recall@10 >= $target at 200 candidates: {$best[0]} ({$best[1]} B/row)\n"
            : "\nNo config reached recall $target at 200 candidates; raise bits/crop or the candidate count.\n");
    }
}
