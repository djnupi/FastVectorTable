<?php

namespace Dj\MysqlVector;

require_once __DIR__ . '/FastVectorTable.php';

/**
 * Database-free stand-in for FastVectorTable (same codes, same search logic, hamming done in PHP).
 * For tests and for tools/tune.php. Slow (PHP popcount), so use samples of a few thousand vectors.
 */
final class InMemoryVectorTable extends FastVectorTable {
    public array $rows = [];
    public function __construct(int $dims, int $bits, ?int $crop = null, ?int $shortCrop = 128, int $shortBits = 128, int $minRows = 200000) { parent::__construct(mysqli_init(), 'mem', $dims, $bits, 2, '7', $crop, $shortCrop, $shortBits, $minRows); }
    public ?bool $lastUsedShort = null;
    protected function estimatedRows(): int { return count($this->rows); }
    public function count(): int { return count($this->rows); }
    protected function saveModel(): void {}
    protected function storeRows(array $rows, int $batch = 200): array { $ids = []; foreach ($rows as $r) { $this->rows[] = $r; $ids[] = count($this->rows); } return $ids; }
    protected function candidateIds(string $hex, int $limit, ?string $hexShort = null, int $limitShort = 0): array {
        $q = hex2bin($hex); $d = []; $rows = $this->rows; $this->lastUsedShort = $hexShort !== null;
        if ($hexShort !== null) {                       // tier 1: short code over all rows, keep $limitShort
            $qs = hex2bin($hexShort); $ds = [];
            foreach ($rows as $i => $r) $ds[$i] = BinaryCodec::hamming($qs, $r['short']);
            asort($ds); $keep = array_slice(array_keys($ds), 0, max($limit, $limitShort)); $rows = array_intersect_key($rows, array_flip($keep));
        }
        foreach ($rows as $i => $r) $d[$i + 1] = BinaryCodec::hamming($q, $r['code']);
        asort($d); return array_slice(array_keys($d), 0, $limit);
    }
    protected function candidateVectors(string $hex, int $limit, ?string $hexShort = null, int $limitShort = 0): array { return $this->fetchVectors($this->candidateIds($hex, $limit, $hexShort, $limitShort)); }
    protected function fetchVectors(array $ids): array { $o = []; foreach ($ids as $id) $o[$id] = unpack('g*', $this->rows[$id - 1]['vec']); return $o; }
    protected function fetchRows(array $ids): array { $o = []; foreach ($ids as $id) $o[$id] = ['fk' => $this->rows[$id - 1]['fk'], 'text' => $this->rows[$id - 1]['text']]; return $o; }
    protected function eachVector(): \Generator { foreach ($this->rows as $i => $r) yield $i + 1 => $r['vec']; }
    protected function sampleIds(int $k): array { mt_srand(5); $ids = range(1, count($this->rows)); shuffle($ids); return array_slice($ids, 0, $k); }
}
