<?php
require __DIR__ . '/_bootstrap.php';
$q = $cfg['token'] !== '' ? '&key=' . urlencode($cfg['token']) : '';
$h = fn($s) => htmlspecialchars((string)$s);
?><!doctype html><meta charset="utf-8"><title>Vector tuning</title>
<body style="font:16px system-ui;max-width:42rem;margin:2rem auto;padding:0 1rem">
<h1>Vector tuning</h1>
<p>Table <b><?= $h($cfg['table']) ?></b>, <?= (int)$cfg['dims'] ?> dims, <?= (int)$cfg['rows'] ?> rows, <?= (int)$cfg['queries'] ?> queries, target recall <?= $h($cfg['target']) ?>.
Change these in <code>web/config.php</code> or with GET variables.</p>
<ul>
<li><a href="tune.php?source=db<?= $q ?>">Run the tuning grid on a sample from the table</a> (takes about a minute)</li>
<li><a href="export.php?<?= ltrim($q, '&') ?>">Export the sample to a file</a>, then <a href="tune.php?source=file<?= $q ?>">tune from the file</a></li>
</ul>
<p>Examples: <code>tune.php?table=docs_fast&amp;dims=384&amp;rows=3000&amp;queries=40&amp;target=0.98</code></p>
