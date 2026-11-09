# FastVectorTable: Faster, more accurate semantic search in MySQL + PHP

a php class for fast and accurate vector search in mysql

Same idea as [the article]((https://medium.com/@dj.luiting/fast-and-accurate-vector-similarity-search-in-native-mysql-there-are-many-existing-specialized-0646ded2a565?source=user_profile_page---------0-------------3db646c36776------------------------))  (binary pre-ranking in MySQL, exact cosine afterwards), with four changes, the starting point was https://github.com/djnupi/mysql-vector.


| # | Change | Why |
|---|--------|-----|
| 1 | Exact rerank in PHP on float32 BLOBs (`vec`), not in MySQL `COSIM` over JSON | `COSIM` does 2 x dims `JSON_EXTRACT(CONCAT(..))` per candidate. PHP: 3 ms for 200 candidates, 29 ms for 2000 (measured, 384-d). `json_decode` of the old JSON column is 6-7x slower. This makes a large `nPre` cheap. |
| 2 | Subtract the dataset mean before taking signs | Real embeddings are not centered; uncentered bits are unbalanced and carry less information. |
| 3 | Random rotation (seeded sign flips + Walsh-Hadamard) before taking signs | Spreads information evenly over the bits, and allows MORE bits than dimensions (SimHash). Cropping to the first 256 of 384 dims throws information away (non-Matryoshka model). No matrix to store. |
| 4 | 1024-bit codes (`BINARY(128)`), same `BIT_COUNT(code ^ UNHEX(?))` query | More bits = better angle estimate. |

## Requirements
PHP 8.0+ with the `mysqli` extension (mysqlnd driver, needed for `get_result()`), and MySQL 8 / MariaDB. No Composer needed: `require 'src/FastVectorTable.php';` loads the other class file itself (namespace `Dj\MysqlVector`). Optional: APCu, to cache the table-size estimate across requests.

## Use
```php
require 'src/FastVectorTable.php';
use Dj\MysqlVector\FastVectorTable;

$t = new FastVectorTable($mysqli, 'docs', dims: 384, bits: 1024);

// One-time setup (run once, e.g. in a migration/CLI):
$t->install();                            // CREATE TABLE IF NOT EXISTS (2 DDL statements)
$t->fit($sampleOf5to20kVectors);          // BEFORE inserting data (learns the mean)
$t->insertMany([['fk' => 1, 'text' => '...', 'vector' => $embedding], ...], batchSize: 200);

// Every web request:
$t->load();                               // reads the stored model only (no DDL), ~0.5 ms
$hits = $t->search($queryEmbedding, n: 10, nPre: 200);
print_r($t->evaluate(50));                // recall@10 and article-style score on YOUR data
```
`initialize()` still exists and simply does `install()` + `load()`; use it for scripts, not for hot request paths.

Notes from timing on a real MySQL 8.0 (160k rows, 2 vCPU):
- `insertMany` uses multi-row INSERTs; the DB share drops from 0.20 to 0.05 ms/row, but PHP encoding (~0.5 ms/row at `rounds: 2`) dominates, so the total gain was ~20% locally.
- `rounds: 1` halves encode time (0.20 vs 0.42 ms) with equal recall on the test sample; verify on your own data with `tools/tune.php`.
- Candidate ids and vectors are fetched in one query (saves a round trip; mainly matters over a network).
- The table-size estimate is cached (in-process 60 s, APCu 300 s if available), so `information_schema` is hit at most once per interval.
- Keep codes as `BINARY(n)`: a BIGINT-column Hamming scan was ~3x slower (158 ms vs 58 ms).
- The two-stage query saved only ~10-20% at 160k rows (62 vs 70 ms); the 200,000-row switch threshold is a reasonable but unproven guess. Tune `twoStageMinRows` on your hardware.

## Fitting the mean (and backing it up)
The only thing learned from your data is the per-dimension mean of a sample of vectors; everything else (rotation signs) comes from the seed.
`fit()` is plain PHP and runs once, after `install()` and before the first insert:

```php
$t->install();
$t->fit($sample);   // array of float arrays: 100-5000 representative embeddings is plenty
```
- It crops and renormalizes each sample vector like the table does, averages per dimension, and does the same for the short key.
- Result: one row (`k = 'model'`) in `{name}_fast_meta`, as JSON with the settings (dims, bits, rounds, seed, crops) and both means. `load()` reads it each request and refuses to continue if the stored settings differ from the constructor's.
- `fit()` throws if the table already has rows (old codes would no longer match). `fit($sample, force: true)` overrides this, but then every row must be re-encoded.
- In tests, 100 random rows gave the same recall as all 30,000; a skewed sample cost ~0.02, and no centering at all cost far more at low bit counts.

**Backup.** The means live in the database, so a normal dump of the table covers them:
```
mysqldump -u USER -p DBNAME docs_fast docs_fast_meta > docs_backup.sql
```
Back up `_fast_meta` together with `_fast`: the codes are only meaningful with the mean and seed they were made with. Also keep the `seed` (and other constructor settings) in your config.
If the mean is lost, new queries cannot be encoded consistently with the stored codes. The stored `vec` column still holds every vector, so `$t->reencodeAll()` can rebuild everything: it learns a new mean from a random sample of the stored vectors (`sampleSize`, default 5000), then re-encodes every row in batches of 500 inside transactions. `reencodeAll(refit: false)` keeps the loaded mean and only re-encodes, which is what you want after changing seed, bits, rounds or crop (construct the table with the NEW settings first). Run it offline: searches during the run see a mix of old and new codes. About 0.5 ms per row, so 100k rows take roughly a minute.

## Tests
`php tests/selftest.php` (no MySQL needed): encoder parity with a numpy reference, plus an end-to-end run on 2000 real embeddings
through an in-memory stand-in for the database. `php tests/mysql_integration.php` runs the actual SQL against a real MySQL.

## Matryoshka models: small codes always stored, mode switches by table size
The small key exists by default: 128 bits from the first 128 dimensions (16 bytes/row). On a non-Matryoshka test sample 256 bits recalled better
(0.885 vs 0.82 at 200 candidates in two-stage mode), so if recall at your `nShort` is short, try `shortBits: 256` - `tools/tune.php` compares both.
```php
// Granite: you store 384 dims (cut + re-normalize the 768 yourself: BinaryCodec::cropNormalize($v, 384))
$t = new FastVectorTable($mysqli, 'docs', dims: 384, bits: 1024);
// the small key is ON by default: shortCropDims: 128, shortBits: 128 (16 B/row), stored next to the full-size key.
// shortCropDims: null switches it off. twoStageMinRows: 200000 is a guess - time both modes on your server.
$t->search($q, n: 10, nPre: 200);   // < 200k rows: scans the 1024-bit key; >= 200k: two-stage
$t->search($q, 10, 200, null, true);  // force two-stage (or false to force the direct scan)
$t->search($q, 10, 200, 2000);        // passing nShort also selects two-stage
print_r($t->prefixCeiling(30, [64, 128, 256], 10, 200)); // how good is the first k dims alone?
```
Rotation mixes all dimensions, so Matryoshka cropping (`cropDims`) is done BEFORE the rotation. Both codes are always stored
and both have a covering index, so the mode can change later without re-encoding. The row count for the switch is the
InnoDB estimate (cached 60 s; MySQL 8 may refresh its statistics only once a day, which is fine for a threshold).

Direct mode (one statement, scans the narrow `idx_code` index):
```sql
SELECT id FROM docs_fast FORCE INDEX (idx_code) ORDER BY BIT_COUNT(code ^ UNHEX(?)) LIMIT :nPre
```
Two-stage mode (inner query selects ONLY id so the covering index `idx_short` answers it; MySQL does not allow LIMIT inside `IN (subquery)`):
```sql
SELECT s.id FROM (
  SELECT id FROM docs_fast FORCE INDEX (idx_short) ORDER BY BIT_COUNT(code_short ^ UNHEX(?)) LIMIT :nShort
) s JOIN docs_fast t ON t.id = s.id
ORDER BY BIT_COUNT(t.code ^ UNHEX(?)) LIMIT :nPre
```
The final rerank always uses the FULL stored vectors. Not yet verified on a real Matryoshka model (BGE-small is not one),
and the SQL has not been run against a MySQL server.

## Tuning on your own vectors (no database needed)
```
php tools/export.php HOST USER PASS DATABASE TABLE 384 3000 vectors.f32   # reads your table, crops to 384 dims, re-normalizes
php tools/tune.php vectors.f32 384 3000 40 0.97
```
Prints the prefix ceiling per crop size, then recall@10 at 50/200 candidates and scan bytes per row for single- and two-tier
settings, and names the cheapest scan that reaches your target recall. (First test run was on BGE-small, which is not Matryoshka.)

### In the browser instead of the command line
Put the folder under your web root (or run `php -S 127.0.0.1:8080 -t web`), edit the defaults in `web/config.php`
(DB login, table, dims, rows, queries, target), then open `web/index.php`.
`web/tune.php` samples straight from the table and prints the grid as it finishes each setting; `web/export.php` writes the sample file.
GET variables override the defaults: `tune.php?table=docs_fast&dims=384&rows=3000&queries=40&target=0.98`.
DB credentials and the file path can NOT be set from the URL. By default only requests from the same machine are accepted;
to use it remotely set a long random `token` in `web/config.local.php` and add `&key=TOKEN` to the URL.
These pages read your data and are CPU-heavy: do not leave them reachable on a public server.

`research/` has the numpy simulations behind the numbers (`sim.py`, `sim_fwht.py`, `sim_ivf.py`).
he numbers (`sim.py`, `sim_fwht.py`, `sim_ivf.py`).
