<?php
// Defaults for the browser tools. Override any of them in config.local.php (same format, only the keys you change),
// or per request with GET variables: ?table=docs_fast&dims=384&rows=2000&queries=30&target=0.97&source=db
return [
    // database (never read from the URL)
    'db'      => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'test'],
    'table'   => 'docs_vectors',     // name_fast (new float32 table) or name_vectors (old JSON table)
    'dims'    => 384,                // resolution you will store (Granite 768 is cut to its first 384, re-normalized)
    'rows'    => 2000,               // sample size (tune: max 5000 - PHP does the popcount, so keep it modest)
    'queries' => 30,
    'target'  => 0.97,               // recall@10 at 200 candidates you want to reach
    'source'  => 'db',               // tune.php: 'db' = sample straight from the table, 'file' = read $out
    'out'     => __DIR__ . '/vectors.f32',

    // access: by default only requests from this machine (127.0.0.1 / ::1) are accepted.
    // To use it from another machine set a long random token and call ?key=THE_TOKEN
    'token'   => '',
];
