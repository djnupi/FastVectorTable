<?php
require_once __DIR__ . '/../src/VectorExport.php';
require_once __DIR__ . '/../src/Tuner.php';

$cfg = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_replace_recursive($cfg, require __DIR__ . '/config.local.php');
}

// ---- access guard: these pages read your data and are CPU-heavy
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$key = (string)($_GET['key'] ?? '');
$ok = $cfg['token'] !== '' ? hash_equals($cfg['token'], $key) : $local;
if (!$ok) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit($cfg['token'] !== '' ? "Forbidden: missing or wrong ?key=\n" : "Forbidden: only localhost is allowed. Set 'token' in web/config.local.php to use it remotely.\n");
}

// ---- GET overrides (whitelist + clamping; the DB credentials and the file path are NOT overridable)
$clamp = fn($v, $lo, $hi) => max($lo, min($hi, $v));
if (isset($_GET['table']) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $_GET['table'])) { $cfg['table'] = $_GET['table']; }
if (isset($_GET['dims']))    { $cfg['dims'] = (int)$_GET['dims']; }
if (isset($_GET['rows']))    { $cfg['rows'] = (int)$_GET['rows']; }
if (isset($_GET['queries'])) { $cfg['queries'] = (int)$_GET['queries']; }
if (isset($_GET['target']))  { $cfg['target'] = (float)$_GET['target']; }
if (isset($_GET['source']) && in_array($_GET['source'], ['db', 'file'], true)) { $cfg['source'] = $_GET['source']; }
$cfg['dims'] = $clamp($cfg['dims'], 8, 4096);
$cfg['queries'] = $clamp($cfg['queries'], 5, 200);
$cfg['target'] = $clamp($cfg['target'], 0.5, 1.0);

/** Plain-text streaming output, flushed as it is produced. */
function streamStart(): void
{
    set_time_limit(0);
    ini_set('memory_limit', '1024M');
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) { ob_end_flush(); }
    ob_implicit_flush(true);
    echo str_repeat(' ', 2048) . "\n";
}
function out(string $s): void { echo $s; flush(); }

function connect(array $cfg): mysqli
{
    try {
        return new mysqli($cfg['db']['host'], $cfg['db']['user'], $cfg['db']['pass'], $cfg['db']['name']);
    } catch (Throwable $e) {
        out("Could not connect to the database: " . $e->getMessage() . "\nCheck 'db' in web/config.php or config.local.php.\n");
        exit;
    }
}
