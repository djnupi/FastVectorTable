<?php

namespace MHz\MysqlVector;

final class VectorExport
{
    /** Crop to the first $dims values, L2-normalize, pack as little-endian float32. */
    public static function toF32(array $v, int $dims): string
    {
        $v = array_slice(array_values($v), 0, $dims);
        if (count($v) < $dims) {
            throw new \RuntimeException('vector has only ' . count($v) . " values, expected at least $dims");
        }
        $n = 0.0;
        foreach ($v as $x) {
            $n += $x * $x;
        }
        $n = sqrt($n) ?: 1e-10;
        return pack('g*', ...array_map(fn($x) => $x / $n, $v));
    }

    /**
     * Random sample of rows from a FastVectorTable table (name ends in _fast: float32 `vec` column)
     * or from an old VectorTable table (JSON `normalized_vector` column). Yields packed float32 blobs.
     * @return \Generator<string>
     */
    public static function sample(\mysqli $db, string $table, int $dims, int $rows): \Generator
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('bad table name');
        }
        $isFast = str_ends_with($table, '_fast');
        $col = $isFast ? 'vec' : 'normalized_vector';
        $res = $db->query("SELECT $col FROM `$table` ORDER BY RAND() LIMIT " . (int)$rows);
        if (!$res) {
            throw new \RuntimeException('query failed: ' . $db->error);
        }
        while ($r = $res->fetch_row()) {
            yield self::toF32($isFast ? array_values(unpack('g*', $r[0])) : json_decode($r[0], true), $dims);
        }
    }

    /** Read a raw float32 file into a list of vectors (arrays). */
    public static function readFile(string $file, int $dims, int $maxRows): array
    {
        $raw = unpack('g*', (string)file_get_contents($file, false, null, 0, $maxRows * $dims * 4));
        $n = intdiv(count($raw), $dims);
        $vecs = [];
        for ($i = 0; $i < $n; $i++) {
            $vecs[] = array_slice($raw, $i * $dims, $dims);
        }
        return $vecs;
    }
}
