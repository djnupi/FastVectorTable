<?php

namespace Dj\MysqlVector;

require_once __DIR__ . '/BinaryCodec.php';

/**
 * Semantic search in plain MySQL + PHP.
 *
 *   stage 1  MySQL : ORDER BY BIT_COUNT(code ^ query_code) LIMIT $nPre     (binary codes, see BinaryCodec)
 *            optional two-tier (Matryoshka): a short code over ALL rows first, then the long code on the survivors
 *   stage 2  PHP   : exact cosine on the candidates, float32 BLOBs         (no JSON, no stored function)
 *   stage 3  MySQL : fetch text for the final $n rows only
 *
 * Matryoshka options (all optional):
 *   $cropDims       build the long code from only the first N dimensions (re-normalized). Default: all.
 *   $shortCropDims  ALSO store a short code from the first N dimensions with $shortBits bits. ON BY DEFAULT: 128 dims,
 *                   128 bits = 16 bytes/row. Pass null/0 to switch off; ignored when it is not smaller than $cropDims. (256 bits recalled better
 *                   256 recalled better in a test on non-Matryoshka data, see README).
 *   $twoStageMinRows  with a short code present, search() uses the two-stage query only when the table has at least
 *                   this many rows (estimate); below it the long code is scanned directly. 0 = always two-stage,
 *                   PHP_INT_MAX = never. The default is a guess: time both modes on your server.
 * Both codes are always stored, so the mode can change later without re-encoding anything.
 * The vectors stored for the final rerank are always the FULL vectors.
 *
 * All database access is in the protected methods at the bottom so it can be replaced in tests.
 */
class FastVectorTable
{
    protected \mysqli $db;
    protected string $table;
    protected string $metaTable;
    protected int $dims;
    protected int $bits;
    protected int $rounds;
    protected string $seed;
    protected int $cropDims;
    protected ?int $shortCrop;
    protected int $shortBits;
    protected BinaryCodec $codec;
    protected ?BinaryCodec $shortCodec = null;
    protected int $twoStageMinRows;
    private ?int $rowsCache = null;
    private float $rowsCacheAt = 0.0;

    public function __construct(\mysqli $db, string $name, int $dims = 384, int $bits = 1024, int $rounds = 2, string $seed = '7',
                                ?int $cropDims = null, ?int $shortCropDims = 128, int $shortBits = 128,
                                int $twoStageMinRows = 200000)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('table name may only contain letters, digits and _');
        }
        $cropDims ??= $dims;
        if ($cropDims < 1 || $cropDims > $dims) {
            throw new \InvalidArgumentException('cropDims must be between 1 and dims');
        }
        // The short key only makes sense if it is coarser than the long one; pass null (or 0) to switch it off.
        if ($shortCropDims !== null && ($shortCropDims < 1 || $shortCropDims >= $cropDims)) {
            $shortCropDims = null;
        }
        $this->db = $db;
        $this->table = $name . '_fast';
        $this->metaTable = $name . '_fast_meta';
        $this->dims = $dims;
        $this->bits = $bits;
        $this->rounds = $rounds;
        $this->seed = $seed;
        $this->cropDims = $cropDims;
        $this->shortCrop = $shortCropDims;
        $this->shortBits = $shortBits;
        $this->twoStageMinRows = $twoStageMinRows;
        $this->codec = new BinaryCodec($cropDims, $bits, $rounds, $seed);
        if ($shortCropDims !== null) {
            $this->shortCodec = new BinaryCodec($shortCropDims, $shortBits, $rounds, $seed . 's');
        }
    }

    public function codec(): BinaryCodec { return $this->codec; }

    /** One-time setup: create the tables and load the model. In a web app call install() once (a deploy step) and load() per request. */
    public function initialize(): void
    {
        $this->install();
        $this->load();
    }

    /** Per-request: read the stored model (the means) only. One small query, no DDL. */
    public function load(): void
    {
        $this->loadModel();
    }

    /** Create the tables if they do not exist. Run once, not on every request. */
    public function install(): void
    {
        $long = $this->codec->bytes();
        $cols = "code BINARY($long) NOT NULL,";
        $keys = "KEY idx_code (code)";                // narrow covering index: the scan never touches the wide rows
        if ($this->shortCodec) {
            $cols .= " code_short BINARY({$this->shortCodec->bytes()}) NOT NULL,";
            $keys .= ", KEY idx_short (code_short)";
        }
        $this->exec("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fk INT UNSIGNED NULL,
            text TEXT CHARACTER SET utf8mb4 NOT NULL,
            vec BLOB NOT NULL,
            $cols
            $keys
        ) ENGINE=InnoDB");
        $this->exec("CREATE TABLE IF NOT EXISTS `{$this->metaTable}` (
            k VARCHAR(32) PRIMARY KEY,
            v MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB");
    }

    /**
     * Learn the dataset mean(s) from a sample of FULL vectors. Call ONCE, before inserting the real data.
     * Changing a mean later changes every code, so it is refused when rows exist (re-insert instead).
     */
    public function fit(array $sampleVectors, bool $force = false): void
    {
        if (!$force && $this->count() > 0) {
            throw new \RuntimeException('Table already has rows; codes would become inconsistent. Empty it first or pass $force.');
        }
        $this->codec->setMean(BinaryCodec::fitMean(array_map(fn($v) => BinaryCodec::cropNormalize($v, $this->cropDims), $sampleVectors)));
        $this->shortCodec?->setMean(BinaryCodec::fitMean(array_map(fn($v) => BinaryCodec::cropNormalize($v, $this->shortCrop), $sampleVectors)));
        $this->saveModel();
    }

    /**
     * Rebuild every code from the stored float32 vectors. Use it after losing the model (mean), after changing
     * seed/bits/rounds/crop (construct the table with the NEW settings first), or to refresh a bad mean.
     * $refit = true: learn a new mean from a random sample of the stored vectors, then re-encode all rows.
     * $refit = false: keep the mean currently loaded, only re-encode (settings changed).
     * Searches during the run see a mix of old and new codes: run it offline. Resumable: not needed, a rerun starts over.
     * @return int number of rows re-encoded
     */
    public function reencodeAll(bool $refit = true, int $sampleSize = 5000, int $batch = 500): int
    {
        if ($refit) {
            $ids = $this->sampleIds($sampleSize);
            if (!$ids) {
                return 0;
            }
            $sample = [];
            foreach ($this->fetchVectors($ids) as $v) {
                $sample[] = array_values($v);
            }
            $this->fit($sample, true);
        }
        $tiered = $this->shortCodec !== null;
        $upd = $this->db->prepare('UPDATE `' . $this->table . '` SET code = UNHEX(?)' . ($tiered ? ', code_short = UNHEX(?)' : '') . ' WHERE id = ?');
        $sel = $this->db->prepare('SELECT id, vec FROM `' . $this->table . '` WHERE id > ? ORDER BY id LIMIT ' . max(1, $batch));
        $last = 0;
        $done = 0;
        while (true) {
            $sel->bind_param('i', $last);
            $sel->execute();
            $rows = $sel->get_result()->fetch_all(MYSQLI_NUM);
            if (!$rows) {
                break;
            }
            $this->db->begin_transaction();
            foreach ($rows as [$id, $blob]) {
                [$long, $short] = $this->codesFor(array_values(unpack('g*', $blob)));
                $lh = bin2hex($long);
                if ($tiered) {
                    $sh = bin2hex($short);
                    $upd->bind_param('ssi', $lh, $sh, $id);
                } else {
                    $upd->bind_param('si', $lh, $id);
                }
                $upd->execute();
                $last = (int)$id;
                $done++;
            }
            $this->db->commit();
        }
        $upd->close();
        $sel->close();
        return $done;
    }

    /** @return array [longCode, shortCode|null] for a FULL vector */
    protected function codesFor(array $v): array
    {
        return [
            $this->codec->encode(BinaryCodec::cropNormalize($v, $this->cropDims)),
            $this->shortCodec?->encode(BinaryCodec::cropNormalize($v, $this->shortCrop)),
        ];
    }

    /**
     * @param array $items list of ['fk' => int|null, 'text' => string, 'vector' => float[]]
     * @param int $batchSize rows per INSERT statement (multi-row inserts are much faster than one statement per row)
     * @return int[] inserted ids
     */
    public function insertMany(array $items, int $batchSize = 200): array
    {
        $rows = [];
        foreach ($items as $it) {
            $v = BinaryCodec::normalize($it['vector']);
            [$long, $short] = $this->codesFor($v);
            $rows[] = ['fk' => $it['fk'] ?? null, 'text' => (string)$it['text'], 'vec' => pack('g*', ...$v), 'code' => $long, 'short' => $short];
        }
        return $this->storeRows($rows, $batchSize);
    }

    /**
     * @param int      $nPre   candidates handed to the exact rerank. 200 is a good start (see README).
     * @param int|null $nShort two-stage only: rows kept by the short code before the long code ranks them.
     *                         Default 10 x $nPre. Passing it explicitly selects the two-stage query.
     * @param bool|null $twoStage force the mode; null = automatic (see $twoStageMinRows)
     * @return array list of ['id','fk','text','similarity'], best first
     */
    public function search(array $vector, int $n = 10, int $nPre = 200, ?int $nShort = null, ?bool $twoStage = null): array
    {
        $q = BinaryCodec::normalize($vector);
        [$long, $short] = $this->codesFor($q);
        $useShort = $short !== null && ($twoStage ?? ($nShort !== null || $this->estimatedRows() >= $this->twoStageMinRows));
        $vecs = $this->candidateVectors(bin2hex($long), max($n, $nPre), $useShort ? bin2hex($short) : null, $nShort ?? 10 * max($n, $nPre));
        if (!$vecs) {
            return [];
        }
        $top = self::rerank($q, $vecs, $n);
        $rows = $this->fetchRows(array_column($top, 0));
        $out = [];
        foreach ($top as [$id, $sim]) {
            if (isset($rows[$id])) {
                $out[] = ['id' => $id, 'fk' => $rows[$id]['fk'], 'text' => $rows[$id]['text'], 'similarity' => $sim];
            }
        }
        return $out;
    }

    /** Brute-force exact search in PHP. Slow (~15 us/row): for measuring recall, not for production. */
    public function exactSearch(array $vector, int $n = 10): array
    {
        $q = BinaryCodec::normalize($vector);
        $sims = [];
        foreach ($this->eachVector() as $id => $blob) {
            $sims[$id] = self::dot($q, unpack('g*', $blob));
        }
        arsort($sims);
        $out = [];
        foreach (array_slice($sims, 0, $n, true) as $id => $s) {
            $out[] = [$id, $s];
        }
        return $out;
    }

    /**
     * Measure on YOUR data: recall@10 and the "score ratio" from the article, for several candidate counts.
     * Queries are existing rows (the row itself is excluded from the answer).
     */
    public function evaluate(int $queries = 50, array $nPres = [10, 50, 200, 500], int $n = 10, ?int $nShort = null): array
    {
        $res = [];
        foreach ($nPres as $np) {
            $res[$np] = ['recall' => 0.0, 'score' => 0.0];
        }
        $ids = $this->sampleIds($queries);
        foreach ($ids as $qid) {
            $q = array_values($this->vectorOf($qid));
            $truth = array_values(array_filter($this->exactSearch($q, $n + 1), fn($r) => $r[0] !== $qid));
            $truth = array_slice($truth, 0, $n);
            $truthIds = array_column($truth, 0);
            $ideal = array_sum(array_column($truth, 1));
            foreach ($nPres as $np) {
                $got = array_values(array_filter($this->search($q, $n + 1, $np, $nShort), fn($r) => $r['id'] !== $qid));
                $got = array_slice($got, 0, $n);
                $res[$np]['recall'] += count(array_intersect(array_column($got, 'id'), $truthIds)) / $n;
                $res[$np]['score'] += $ideal > 0 ? array_sum(array_column($got, 'similarity')) / $ideal : 0;
            }
        }
        foreach ($res as $np => $r) {
            $res[$np] = ['recall' => round($r['recall'] / count($ids), 3), 'score' => round($r['score'] / count($ids), 3)];
        }
        return $res;
    }

    /**
     * Matryoshka helper: if candidates were chosen by EXACT cosine on only the first k dimensions (re-normalized),
     * how often would the true top-$n (full vectors) be among the best $nPre of them?
     * That is the ceiling for any binary code built from k dimensions at that candidate count.
     * Pick the smallest k that stays close to 1.0.
     * @return array k => fraction of the true top-$n found within $nPre candidates
     */
    public function prefixCeiling(int $queries = 30, array $ks = [64, 128, 256], int $n = 10, int $nPre = 100): array
    {
        $ks = array_values(array_unique(array_filter(array_map('intval', $ks), fn($k) => $k >= 1 && $k < $this->dims)));
        sort($ks);
        $ks[] = $this->dims;                                   // the full vector, for the "truth" side
        $Q = [];
        foreach ($this->sampleIds($queries) as $qid) {
            $Q[$qid] = array_values($this->vectorOf($qid));
        }
        $top = [];                                             // [qid][k] => id => sim (trimmed)
        $m = max($n, $nPre);
        $push = function (array &$a, int $id, float $s) use ($m) {
            $a[$id] = $s;
            if (count($a) >= 4 * $m) {
                arsort($a);
                $a = array_slice($a, 0, $m, true);
            }
        };
        foreach ($this->eachVector() as $id => $blob) {
            $v = unpack('g*', $blob);
            foreach ($Q as $qid => $q) {
                if ($id === $qid) {
                    continue;
                }
                $dot = $nq = $nv = 0.0;
                $ki = 0;
                foreach ($q as $i => $a) {
                    $b = $v[$i + 1];
                    $dot += $a * $b;
                    $nq += $a * $a;
                    $nv += $b * $b;
                    if ($i + 1 === $ks[$ki]) {
                        $top[$qid][$ks[$ki]] ??= [];
                        $push($top[$qid][$ks[$ki]], $id, $dot / (sqrt($nq * $nv) ?: 1e-10));
                        $ki++;
                    }
                }
            }
        }
        $out = array_fill_keys(array_slice($ks, 0, -1), 0.0);
        foreach ($Q as $qid => $_) {
            arsort($top[$qid][$this->dims]);
            $truth = array_keys(array_slice($top[$qid][$this->dims], 0, $n, true));
            foreach ($out as $k => $_r) {
                arsort($top[$qid][$k]);
                $out[$k] += count(array_intersect(array_keys(array_slice($top[$qid][$k], 0, $nPre, true)), $truth)) / $n;
            }
        }
        return array_map(fn($x) => round($x / count($Q), 3), $out);
    }

    public function vectorOf(int $id): array
    {
        $v = $this->fetchVectors([$id]);
        return isset($v[$id]) ? array_values($v[$id]) : [];
    }

    /**
     * Exact cosine on candidates. $vecs: id => unpack('g*') array (1-indexed). $q: normalized, 0-indexed.
     * @return array list of [id, similarity], best first
     */
    public static function rerank(array $q, array $vecs, int $n): array
    {
        $sims = [];
        foreach ($vecs as $id => $v) {
            $sims[$id] = self::dot($q, $v);
        }
        arsort($sims);
        $out = [];
        foreach (array_slice($sims, 0, $n, true) as $id => $s) {
            $out[] = [$id, $s];
        }
        return $out;
    }

    /** $q 0-indexed, $v 1-indexed (as returned by unpack) or 0-indexed list; handled by offset. */
    private static function dot(array $q, array $v): float
    {
        $off = array_key_exists(0, $v) ? 0 : 1;
        $s = 0.0;
        for ($i = 0, $d = count($q); $i < $d; $i++) {
            $s += $q[$i] * $v[$i + $off];
        }
        return $s;
    }

    // ------------------------------------------------------------------ database access (all SQL lives here)

    protected function exec(string $sql): void
    {
        if (!$this->db->query($sql)) {
            throw new \RuntimeException($this->db->error);
        }
    }

    /**
     * Row count estimate for the mode switch (InnoDB estimate; may lag, which is fine for a threshold).
     * Cached per process (60 s) and, when APCu is enabled, across requests (300 s), so a web request normally
     * costs no extra query. Without APCu in PHP-FPM every request asks the server once.
     */
    protected function estimatedRows(): int
    {
        if ($this->rowsCache !== null && microtime(true) - $this->rowsCacheAt < 60) {
            return $this->rowsCache;
        }
        $key = $this->cacheKey();
        $n = $this->cacheGet($key);
        if ($n === null) {
            $n = $this->queryRowEstimate();
            $this->cacheSet($key, $n, 300);
        }
        $this->rowsCacheAt = microtime(true);
        return $this->rowsCache = $n;
    }

    protected function queryRowEstimate(): int
    {
        $r = $this->db->query("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->table}'");
        $row = $r ? $r->fetch_row() : null;
        $n = $row ? (int)$row[0] : 0;
        return $n > 0 ? $n : $this->count();   // fresh table: statistics not populated yet
    }

    protected function cacheKey(): string
    {
        return 'mhz_fast_rows:' . $this->table . ':' . crc32((string)$this->db->host_info);
    }

    protected function cacheGet(string $key): ?int
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $v = apcu_fetch($key, $ok);
            return $ok ? (int)$v : null;
        }
        return null;
    }

    protected function cacheSet(string $key, int $value, int $ttl): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store($key, $value, $ttl);
        }
    }

    public function count(): int
    {
        $r = $this->db->query("SELECT COUNT(*) FROM `{$this->table}`");
        return $r ? (int)$r->fetch_row()[0] : 0;
    }

    public function delete(int $id): void
    {
        $this->exec('DELETE FROM `' . $this->table . '` WHERE id = ' . (int)$id);
    }

    protected function modelParams(): array
    {
        return ['dims' => $this->dims, 'bits' => $this->bits, 'rounds' => $this->rounds, 'seed' => $this->seed,
            'crop' => $this->cropDims, 'shortCrop' => $this->shortCrop, 'shortBits' => $this->shortBits];
    }

    protected function loadModel(): void
    {
        $r = $this->db->query("SELECT v FROM `{$this->metaTable}` WHERE k = 'model'");
        $row = $r ? $r->fetch_row() : null;
        if (!$row) {
            return;
        }
        $m = json_decode($row[0], true);
        foreach ($this->modelParams() as $k => $v) {
            if (($m[$k] ?? null) !== $v) {
                throw new \RuntimeException("Stored model parameter '$k' differs from the constructor arguments.");
            }
        }
        $this->codec->setMean($m['mean']);
        if ($this->shortCodec && isset($m['shortMean'])) {
            $this->shortCodec->setMean($m['shortMean']);
        }
    }

    protected function saveModel(): void
    {
        $json = json_encode($this->modelParams() + ['mean' => $this->codec->mean(), 'shortMean' => $this->shortCodec?->mean()]);
        $st = $this->db->prepare("REPLACE INTO `{$this->metaTable}` (k, v) VALUES ('model', ?)");
        $st->bind_param('s', $json);
        $st->execute();
        $st->close();
    }

    /** Multi-row INSERT with $rows value tuples. */
    protected function insertSql(int $rows): string
    {
        $tiered = $this->shortCodec !== null;
        $tuple = $tiered ? '(?, ?, ?, UNHEX(?), UNHEX(?))' : '(?, ?, ?, UNHEX(?))';
        $cols = $tiered ? 'fk, text, vec, code, code_short' : 'fk, text, vec, code';
        return "INSERT INTO `{$this->table}` ($cols) VALUES " . implode(', ', array_fill(0, $rows, $tuple));
    }

    /** @return int[] ids, assuming consecutive auto-increment values within one statement (step = auto_increment_increment) */
    protected function storeRows(array $rows, int $batch = 200): array
    {
        $tiered = $this->shortCodec !== null;
        $rowTypes = $tiered ? 'issss' : 'isss';
        $step = $this->autoIncrementStep();
        $ids = [];
        $stmts = [];
        $this->db->begin_transaction();
        try {
            foreach (array_chunk($rows, max(1, $batch)) as $chunk) {
                $c = count($chunk);
                $st = $stmts[$c] ??= $this->db->prepare($this->insertSql($c));
                if (!$st) {
                    throw new \RuntimeException($this->db->error);
                }
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $r['fk'], $r['text'], $r['vec'], bin2hex($r['code']));
                    if ($tiered) {
                        $params[] = bin2hex($r['short']);
                    }
                }
                $st->bind_param(str_repeat($rowTypes, $c), ...$params);
                if (!$st->execute()) {
                    throw new \RuntimeException($st->error);
                }
                for ($k = 0; $k < $c; $k++) {
                    $ids[] = $st->insert_id + $k * $step;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        } finally {
            foreach ($stmts as $st) {
                if ($st) {
                    $st->close();
                }
            }
        }
        return $ids;
    }

    private ?int $autoStep = null;

    protected function autoIncrementStep(): int
    {
        if ($this->autoStep === null) {
            $r = $this->db->query('SELECT @@auto_increment_increment');
            $this->autoStep = $r ? max(1, (int)$r->fetch_row()[0]) : 1;
        }
        return $this->autoStep;
    }

    /**
     * The SELECT id ... statement of stage 1 (1 or 2 `?` placeholders: the short code first, then the long code).
     * Two-stage: the inner query selects ONLY id, so it can be answered from the covering index idx_short; it keeps
     * $limitShort ids. The outer query joins back to read the long code of just those rows and ranks them.
     * A derived table is used because MySQL does not allow LIMIT inside IN (subquery).
     * FORCE INDEX makes the scans read the narrow index instead of the wide table rows.
     */
    protected function candidateSql(bool $twoStage, int $limit, int $limitShort): string
    {
        $t = "`{$this->table}`";
        if (!$twoStage) {
            return "SELECT id FROM $t FORCE INDEX (idx_code) ORDER BY BIT_COUNT(code ^ UNHEX(?)) LIMIT " . (int)$limit;
        }
        return "SELECT s.id FROM (SELECT id FROM $t FORCE INDEX (idx_short) ORDER BY BIT_COUNT(code_short ^ UNHEX(?)) LIMIT "
            . (int)max($limit, $limitShort) . ") s JOIN $t t ON t.id = s.id ORDER BY BIT_COUNT(t.code ^ UNHEX(?)) LIMIT " . (int)$limit;
    }

    /** Stage 1 in one statement, vectors included: ids from candidateSql(), joined back for their `vec`. @return array id => unpack('g*') array */
    protected function candidateVectors(string $hexCode, int $limit, ?string $hexShort = null, int $limitShort = 0): array
    {
        $sql = "SELECT v.id, v.vec FROM (" . $this->candidateSql($hexShort !== null, $limit, $limitShort) . ") c JOIN `{$this->table}` v ON v.id = c.id";
        $st = $this->db->prepare($sql);
        if (!$st) {
            throw new \RuntimeException($this->db->error);
        }
        $hexShort === null ? $st->bind_param('s', $hexCode) : $st->bind_param('ss', $hexShort, $hexCode);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_row()) {
            $out[(int)$row[0]] = unpack('g*', $row[1]);
        }
        $st->close();
        return $out;
    }

    /** Stage 1, ids only. @return int[] ids with the smallest Hamming distance to the query code. */
    protected function candidateIds(string $hexCode, int $limit, ?string $hexShort = null, int $limitShort = 0): array
    {
        $st = $this->db->prepare($this->candidateSql($hexShort !== null, $limit, $limitShort));
        if (!$st) {
            throw new \RuntimeException($this->db->error);
        }
        $hexShort === null ? $st->bind_param('s', $hexCode) : $st->bind_param('ss', $hexShort, $hexCode);
        $st->execute();
        $st->bind_result($id);
        $ids = [];
        while ($st->fetch()) {
            $ids[] = $id;
        }
        $st->close();
        return $ids;
    }

    /** @return array id => unpack('g*') array */
    protected function fetchVectors(array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        $r = $this->db->query("SELECT id, vec FROM `{$this->table}` WHERE id IN ($in)");
        $out = [];
        while ($row = $r->fetch_row()) {
            $out[(int)$row[0]] = unpack('g*', $row[1]);
        }
        return $out;
    }

    /** @return array id => ['fk' =>, 'text' =>] */
    protected function fetchRows(array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        $r = $this->db->query("SELECT id, fk, text FROM `{$this->table}` WHERE id IN ($in)");
        $out = [];
        while ($row = $r->fetch_row()) {
            $out[(int)$row[0]] = ['fk' => $row[1] === null ? null : (int)$row[1], 'text' => $row[2]];
        }
        return $out;
    }

    /** @return \Generator id => raw float32 blob */
    protected function eachVector(): \Generator
    {
        $r = $this->db->query("SELECT id, vec FROM `{$this->table}`", MYSQLI_USE_RESULT);
        while ($row = $r->fetch_row()) {
            yield (int)$row[0] => $row[1];
        }
        $r->free();
    }

    /** @return int[] */
    protected function sampleIds(int $k): array
    {
        $r = $this->db->query("SELECT id FROM `{$this->table}` ORDER BY RAND() LIMIT " . (int)$k);
        $ids = [];
        while ($row = $r->fetch_row()) {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }
}
